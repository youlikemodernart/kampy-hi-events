<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use DateTimeImmutable;
use DateTimeInterface;
use HiEvents\DomainObjects\Enums\FinancialAppendClassification;
use HiEvents\DomainObjects\Enums\FinancialSnapshotKind;
use HiEvents\DomainObjects\Enums\FinancialSourceObjectKind;
use HiEvents\DomainObjects\Enums\FinancialSourceSystem;
use HiEvents\DomainObjects\Status\FinancialFreshness;
use HiEvents\DomainObjects\Status\FinancialMappingDisposition;
use HiEvents\DomainObjects\Status\FinancialReconciliationStatus;
use HiEvents\Exceptions\FinancialCommitOutcomeUnknownException;
use HiEvents\Exceptions\FinancialPersistenceConflictException;
use HiEvents\Repository\Interfaces\FinancialPersistenceRepositoryInterface;
use HiEvents\Services\Domain\Financial\DTOs\FinancialAppendResultDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialPersistedReceiptDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialPersistedSnapshotDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialPlanRevisionDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialScopeDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSnapshotBatchDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSnapshotDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSnapshotQueryDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSnapshotRecordDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSourceMappingRevisionDTO;
use HiEvents\Services\Domain\Financial\FinancialPersistencePlanner;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Query\Builder;
use InvalidArgumentException;
use JsonException;
use LogicException;
use Throwable;

class FinancialPersistenceRepository implements FinancialPersistenceRepositoryInterface
{
    private const MAX_SERIALIZATION_RETRIES = 3;

    private const RETRYABLE_DATABASE_CODES = ['40001', '40P01', '23505'];

    public function __construct(
        private readonly DatabaseManager $db,
        private readonly FinancialPersistencePlanner $planner,
    ) {}

    public function appendScope(FinancialScopeDTO $scope): FinancialAppendResultDTO
    {
        $this->planner->validateScope($scope);

        return $this->runSerializable(
            function (Connection $connection) use ($scope): FinancialAppendResultDTO {
                $rows = $connection->table('financial_scopes')
                    ->where(function (Builder $query) use ($scope): void {
                        $query->where('scope_key', $scope->scopeKey)
                            ->orWhere(function (Builder $identity) use ($scope): void {
                                $identity->where('account_id', $scope->accountId)
                                    ->where('organizer_id', $scope->organizerId)
                                    ->where('event_id', $scope->eventId)
                                    ->where('university_id', trim($scope->universityId))
                                    ->where('cycle_id', trim($scope->cycleId));
                            });
                    })
                    ->lockForUpdate()
                    ->get();

                if ($rows->isNotEmpty()) {
                    if ($rows->count() !== 1 || ! $this->scopeContentMatches($rows->first(), $scope)) {
                        throw new FinancialPersistenceConflictException(
                            'Financial scope key or exact identity is already bound to different content.',
                        );
                    }

                    return $this->appendResult(
                        'append_scope',
                        FinancialAppendClassification::UNCHANGED_REPLAY,
                        false,
                        false,
                        false,
                        scopeKey: $scope->scopeKey,
                    );
                }

                $connection->table('financial_scopes')->insert([
                    'scope_key' => $scope->scopeKey,
                    'account_id' => $scope->accountId,
                    'organizer_id' => $scope->organizerId,
                    'event_id' => $scope->eventId,
                    'university_id' => trim($scope->universityId),
                    'cycle_id' => trim($scope->cycleId),
                    'timezone' => $scope->timezone,
                    'currency' => $scope->currency,
                    'recorded_at' => $this->planner->instant($scope->recordedAt),
                ]);

                return $this->appendResult(
                    'append_scope',
                    FinancialAppendClassification::NEW_SCOPE,
                    true,
                    false,
                    true,
                    scopeKey: $scope->scopeKey,
                );
            },
            fn (FinancialAppendResultDTO $result): bool => ! $result->appended || $this->confirmScope($scope),
        );
    }

    public function appendMappingRevision(
        FinancialSourceMappingRevisionDTO $mapping,
    ): FinancialAppendResultDTO {
        $this->planner->validateMapping($mapping);

        return $this->runSerializable(
            function (Connection $connection) use ($mapping): FinancialAppendResultDTO {
                $this->assertScopeReference(
                    $connection,
                    $mapping->financialScopeId,
                    $mapping->scopeKey,
                    $mapping->universityId,
                    $mapping->cycleId,
                );
                $existing = $this->selectMappingRevisions($connection, $mapping->mappingKey, true);
                $plan = $this->planner->planMappingAppend($mapping, $existing);

                if ($plan->append) {
                    $connection->table('financial_source_mapping_revisions')->insert(
                        $this->mappingAttributes($mapping),
                    );
                }

                return $this->appendResult(
                    'append_mapping_revision',
                    $plan->classification,
                    $plan->append,
                    false,
                    $plan->promotable,
                    mappingRevisionId: $mapping->mappingRevisionId,
                );
            },
            fn (FinancialAppendResultDTO $result): bool => ! $result->appended || $this->confirmMapping($mapping),
        );
    }

    public function appendSnapshotBatch(FinancialSnapshotBatchDTO $batch): FinancialAppendResultDTO
    {
        $this->planner->validateBatch($batch);

        return $this->runSerializable(
            function (Connection $connection) use ($batch): FinancialAppendResultDTO {
                $this->assertScopeReference(
                    $connection,
                    $batch->snapshot->financialScopeId,
                    $batch->snapshot->scopeKey,
                    $batch->snapshot->universityId,
                    $batch->snapshot->cycleId,
                );
                $existingSnapshots = $this->selectPlanningSnapshots($connection, $batch, true);
                $existingSnapshotReceiptRows = $connection
                    ->table('financial_reconciliation_receipts')
                    ->where('snapshot_id', $batch->snapshot->snapshotId)
                    ->get();
                $existingSourceReceiptIds = $existingSnapshotReceiptRows
                    ->pluck('source_receipt_id')
                    ->map(static fn (mixed $value): string => (string) $value)
                    ->all();
                $matchingSourceReceiptRows = $connection
                    ->table('financial_reconciliation_receipts')
                    ->where('source_receipt_id', $batch->receipt->sourceReceiptId)
                    ->get();
                $this->assertExistingSourceReceiptMatches($matchingSourceReceiptRows->all(), $batch);
                $plan = $this->planner->planSnapshotAppend(
                    $batch,
                    $existingSnapshots,
                    $existingSourceReceiptIds,
                );
                if (! $plan->appendSnapshot) {
                    $this->assertPersistedSnapshotMatchesBatch($connection, $batch);
                }

                if ($plan->appendSnapshot) {
                    $connection->table('financial_snapshots')->insert(
                        $this->snapshotAttributes($batch->snapshot),
                    );
                    foreach ($batch->records as $record) {
                        $connection->table('financial_snapshot_records')->insert(
                            $this->recordAttributes($record, $batch->snapshot->importedAt),
                        );
                    }
                    if ($plan->appendPlanRevision && $batch->planRevision !== null) {
                        $connection->table('financial_plan_revisions')->insert(
                            $this->planAttributes($batch->planRevision, $batch->snapshot->importedAt),
                        );
                    }
                }
                if ($plan->appendReceipt && $plan->receipt !== null) {
                    $connection->table('financial_reconciliation_receipts')->insert(
                        $this->receiptAttributes($plan->receipt),
                    );
                }

                return $this->appendResult(
                    'append_snapshot_batch',
                    $plan->classification,
                    $plan->appendSnapshot,
                    $plan->appendReceipt,
                    $plan->promotionEligible,
                    snapshotId: $batch->snapshot->snapshotId,
                    persistenceReceiptId: $plan->receipt?->persistenceReceiptId,
                );
            },
            fn (FinancialAppendResultDTO $result): bool => $this->confirmSnapshotAppend($batch, $result),
        );
    }

    public function getLatestPromotable(
        FinancialSnapshotQueryDTO $query,
    ): ?FinancialPersistedSnapshotDTO {
        return $this->getLatestFromView('financial_latest_promotable_snapshots', $query);
    }

    public function getLatestSourceControlled(
        FinancialSnapshotQueryDTO $query,
    ): ?FinancialPersistedSnapshotDTO {
        return $this->getLatestFromView('financial_latest_source_controlled_snapshots', $query);
    }

    public function getSnapshotById(
        string $snapshotId,
        FinancialSnapshotQueryDTO $query,
    ): ?FinancialPersistedSnapshotDTO {
        $this->assertDigest($snapshotId, 'snapshotId');
        $this->validateQuery($query);
        $this->assertPostgres($this->db->connection());

        return $this->readPersistedSnapshot($snapshotId, $query);
    }

    private function getLatestFromView(
        string $view,
        FinancialSnapshotQueryDTO $query,
    ): ?FinancialPersistedSnapshotDTO {
        $this->validateQuery($query);
        $connection = $this->db->connection();
        $this->assertPostgres($connection);
        $selected = $this->applyExactScopeQuery($connection->table($view), $query)
            ->orderByDesc('source_as_of_at')
            ->orderByDesc('receipt_generated_at')
            ->orderBy('stream_key')
            ->orderBy('snapshot_id')
            ->orderBy('persistence_receipt_id')
            ->first(['snapshot_id', 'persistence_receipt_id']);

        if ($selected === null) {
            return null;
        }

        return $this->readPersistedSnapshot(
            (string) $selected->snapshot_id,
            $query,
            (string) $selected->persistence_receipt_id,
        );
    }

    private function readPersistedSnapshot(
        string $snapshotId,
        FinancialSnapshotQueryDTO $query,
        ?string $persistenceReceiptId = null,
    ): ?FinancialPersistedSnapshotDTO {
        $connection = $this->db->connection();
        $snapshotRow = $this->applyExactScopeQuery(
            $connection->table('financial_snapshots as snapshot')
                ->join('financial_scopes as scope', 'scope.id', '=', 'snapshot.financial_scope_id'),
            $query,
            'scope',
            'snapshot',
        )
            ->where('snapshot.snapshot_id', $snapshotId)
            ->first([
                'snapshot.*',
                'scope.scope_key',
                'scope.university_id as scope_university_id',
                'scope.cycle_id as scope_cycle_id',
            ]);
        if ($snapshotRow === null) {
            return null;
        }

        $recordRows = $connection->table('financial_snapshot_records')
            ->where('snapshot_id', $snapshotId)
            ->orderBy('record_ordinal')
            ->get();
        $planRow = $connection->table('financial_plan_revisions')
            ->where('snapshot_id', $snapshotId)
            ->first();
        $receiptQuery = $connection->table('financial_reconciliation_receipts')
            ->where('snapshot_id', $snapshotId);
        if ($persistenceReceiptId !== null) {
            $receiptQuery->where('persistence_receipt_id', $persistenceReceiptId);
        }
        $receiptRow = $receiptQuery
            ->orderByDesc('generated_at')
            ->orderByDesc('id')
            ->first();

        return new FinancialPersistedSnapshotDTO(
            $this->snapshotFromRow($snapshotRow),
            $recordRows->map(fn (object $row): FinancialSnapshotRecordDTO => $this->recordFromRow($row))->all(),
            $planRow === null ? null : $this->planFromRow($planRow),
            $receiptRow === null ? null : $this->receiptFromRow($receiptRow),
        );
    }

    /**
     * @param  callable(Connection): FinancialAppendResultDTO  $work
     * @param  callable(FinancialAppendResultDTO): bool  $confirmCommitted
     */
    private function runSerializable(callable $work, callable $confirmCommitted): FinancialAppendResultDTO
    {
        for ($attempt = 1; $attempt <= self::MAX_SERIALIZATION_RETRIES + 1; $attempt++) {
            $connection = $this->db->connection();
            $this->assertPostgres($connection);
            $phase = 'begin';
            $result = null;

            try {
                $connection->beginTransaction();
                $connection->statement('SET TRANSACTION ISOLATION LEVEL SERIALIZABLE');
                $phase = 'work';
                $result = $work($connection);
                $phase = 'commit';
                $this->commitTransaction($connection);

                return $this->withOutcome($result, $attempt, 'committed');
            } catch (Throwable $exception) {
                if ($phase !== 'commit') {
                    $this->safeRollback($connection);
                    if ($this->isRetryable($exception) && $attempt <= self::MAX_SERIALIZATION_RETRIES) {
                        continue;
                    }
                    throw $exception;
                }

                $connectionName = $connection->getName();
                $this->safeRollback($connection);
                $this->db->purge($connectionName);
                if ($result === null) {
                    throw new FinancialCommitOutcomeUnknownException(
                        'Financial commit failed before a deterministic append result existed.',
                        0,
                        $exception,
                    );
                }

                try {
                    if ($confirmCommitted($result)) {
                        return $this->withOutcome(
                            $result,
                            $attempt,
                            'confirmed_after_uncertain_commit',
                        );
                    }
                } catch (Throwable $readbackException) {
                    throw new FinancialCommitOutcomeUnknownException(
                        'Financial commit failed and deterministic readback also failed: '
                        .$readbackException->getMessage(),
                        0,
                        $exception,
                    );
                }

                if ($this->isRetryable($exception) && $attempt <= self::MAX_SERIALIZATION_RETRIES) {
                    continue;
                }

                throw new FinancialCommitOutcomeUnknownException(
                    'Financial commit failed and deterministic IDs were absent on readback.',
                    0,
                    $exception,
                );
            }
        }

        throw new LogicException('Financial transaction retry loop exhausted unexpectedly.');
    }

    protected function commitTransaction(Connection $connection): void
    {
        $connection->commit();
    }

    private function selectMappingRevisions(
        Connection $connection,
        string $mappingKey,
        bool $lock,
    ): array {
        $query = $connection->table('financial_source_mapping_revisions as mapping')
            ->join('financial_scopes as scope', 'scope.id', '=', 'mapping.financial_scope_id')
            ->where('mapping.mapping_key', $mappingKey)
            ->orderBy('mapping.revision_number')
            ->orderBy('mapping.id')
            ->select([
                'mapping.*',
                'scope.scope_key',
                'scope.university_id as scope_university_id',
                'scope.cycle_id as scope_cycle_id',
            ]);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()
            ->map(fn (object $row): FinancialSourceMappingRevisionDTO => $this->mappingFromRow($row))
            ->all();
    }

    private function selectPlanningSnapshots(
        Connection $connection,
        FinancialSnapshotBatchDTO $batch,
        bool $lock,
    ): array {
        $query = $connection->table('financial_snapshots as snapshot')
            ->join('financial_scopes as scope', 'scope.id', '=', 'snapshot.financial_scope_id')
            ->where(function (Builder $where) use ($batch): void {
                $where->where('snapshot.stream_key', $batch->snapshot->streamKey)
                    ->orWhere('snapshot.source_version_key', $batch->snapshot->sourceVersionKey);
            })
            ->orderBy('snapshot.source_as_of_at')
            ->orderBy('snapshot.id')
            ->select([
                'snapshot.*',
                'scope.scope_key',
                'scope.university_id as scope_university_id',
                'scope.cycle_id as scope_cycle_id',
            ]);
        if ($lock) {
            $query->lockForUpdate();
        }

        return $query->get()
            ->map(fn (object $row): FinancialSnapshotDTO => $this->snapshotFromRow($row))
            ->all();
    }

    private function assertScopeReference(
        Connection $connection,
        int $financialScopeId,
        string $scopeKey,
        string $universityId,
        string $cycleId,
    ): void {
        $row = $connection->table('financial_scopes')
            ->where('id', $financialScopeId)
            ->where('scope_key', $scopeKey)
            ->where('university_id', trim($universityId))
            ->where('cycle_id', trim($cycleId))
            ->first();
        if ($row === null) {
            throw new FinancialPersistenceConflictException(
                'Financial persistence input does not reference the exact stored scope.',
            );
        }
    }

    /** @param list<object> $receiptRows */
    private function assertExistingSourceReceiptMatches(
        array $receiptRows,
        FinancialSnapshotBatchDTO $batch,
    ): void {
        foreach ($receiptRows as $receiptRow) {
            if ((string) $receiptRow->source_receipt_id !== $batch->receipt->sourceReceiptId) {
                continue;
            }
            if (! $this->sourceReceiptRowMatches($receiptRow, $batch)) {
                throw new FinancialPersistenceConflictException(
                    'Existing source receipt ID is bound to different immutable content.',
                );
            }
        }
    }

    private function assertPersistedSnapshotMatchesBatch(
        Connection $connection,
        FinancialSnapshotBatchDTO $batch,
        bool $includeImportMetadata = false,
    ): void {
        $snapshot = $connection->table('financial_snapshots')
            ->where('snapshot_id', $batch->snapshot->snapshotId)
            ->first();
        if ($snapshot === null
            || (string) $snapshot->stream_key !== $batch->snapshot->streamKey
            || (string) $snapshot->source_version_key !== $batch->snapshot->sourceVersionKey
            || (int) $snapshot->financial_scope_id !== $batch->snapshot->financialScopeId
            || (string) $snapshot->snapshot_kind !== $batch->snapshot->snapshotKind->value
            || (string) $snapshot->source_system !== $batch->snapshot->sourceSystem->value
            || (string) $snapshot->source_namespace !== trim($batch->snapshot->sourceNamespace)
            || (string) $snapshot->adapter_version !== trim($batch->snapshot->adapterVersion)
            || $this->planner->instant($this->date($snapshot->source_as_of_at))
                !== $this->planner->instant($batch->snapshot->sourceAsOfAt)
            || ($includeImportMetadata
                && $this->planner->instant($this->date($snapshot->imported_at))
                    !== $this->planner->instant($batch->snapshot->importedAt))
            || $this->nullableString($snapshot->policy_version) !== $batch->snapshot->policyVersion
            || (string) $snapshot->content_fingerprint !== $batch->snapshot->contentFingerprint
            || (string) $snapshot->reconciliation_status !== $batch->snapshot->status->value
            || (bool) $snapshot->source_publishable !== $batch->snapshot->sourcePublishable
            || (bool) $snapshot->policy_publishable !== $batch->snapshot->policyPublishable
            || (int) $snapshot->record_count !== $batch->snapshot->recordCount
            || $this->planner->digest(
                $this->decodedJson($snapshot->summary_json, 'snapshot.summary_json'),
            ) !== $this->planner->digest($batch->snapshot->summary)) {
            throw new FinancialPersistenceConflictException(
                'Existing financial snapshot ID is bound to different immutable content.',
            );
        }

        $persistedRecords = $connection->table('financial_snapshot_records')
            ->where('snapshot_id', $batch->snapshot->snapshotId)
            ->orderBy('record_ordinal')
            ->get();
        if ($persistedRecords->count() !== count($batch->records)) {
            throw new FinancialPersistenceConflictException(
                'Existing financial snapshot record count differs from the replay batch.',
            );
        }
        foreach ($batch->records as $index => $record) {
            $persisted = $persistedRecords[$index];
            if ($this->recordFromRow($persisted)->toArray() !== $record->toArray()) {
                throw new FinancialPersistenceConflictException(
                    'Existing financial snapshot records differ from the replay batch.',
                );
            }
        }

        $persistedPlan = $connection->table('financial_plan_revisions')
            ->where('snapshot_id', $batch->snapshot->snapshotId)
            ->first();
        if (($persistedPlan === null ? null : $this->planFromRow($persistedPlan)->toArray())
            !== $batch->planRevision?->toArray()) {
            throw new FinancialPersistenceConflictException(
                'Existing financial plan revision differs from the replay batch.',
            );
        }
    }

    private function confirmScope(FinancialScopeDTO $scope): bool
    {
        $row = $this->db->connection()->table('financial_scopes')
            ->where('scope_key', $scope->scopeKey)
            ->first();

        return $row !== null && $this->scopeRowMatches($row, $scope);
    }

    private function confirmMapping(FinancialSourceMappingRevisionDTO $mapping): bool
    {
        $row = $this->db->connection()
            ->table('financial_source_mapping_revisions as mapping')
            ->join('financial_scopes as scope', 'scope.id', '=', 'mapping.financial_scope_id')
            ->where('mapping.mapping_revision_id', $mapping->mappingRevisionId)
            ->first([
                'mapping.*',
                'scope.scope_key',
                'scope.university_id as scope_university_id',
                'scope.cycle_id as scope_cycle_id',
            ]);
        if ($row === null) {
            return false;
        }

        return $this->mappingFromRow($row)->toArray() === $mapping->toArray();
    }

    private function confirmSnapshotAppend(
        FinancialSnapshotBatchDTO $batch,
        FinancialAppendResultDTO $result,
    ): bool {
        if (! $result->appended && ! $result->appendedReceipt) {
            return true;
        }
        $connection = $this->db->connection();
        if ($result->appended) {
            $snapshotExists = $connection->table('financial_snapshots')
                ->where('snapshot_id', $batch->snapshot->snapshotId)
                ->exists();
            if (! $snapshotExists) {
                return false;
            }
            $this->assertPersistedSnapshotMatchesBatch($connection, $batch, true);
        }
        if ($result->appendedReceipt) {
            $receipt = $connection->table('financial_reconciliation_receipts')
                ->where('persistence_receipt_id', $result->persistenceReceiptId)
                ->first();
            if ($receipt === null
                || ! $this->persistedReceiptRowMatches($receipt, $batch, $result)) {
                return false;
            }
        }

        return true;
    }

    private function sourceReceiptRowMatches(
        object $row,
        FinancialSnapshotBatchDTO $batch,
    ): bool {
        $receipt = $batch->receipt;

        return (string) $row->source_receipt_id === $receipt->sourceReceiptId
            && (string) $row->snapshot_id === $batch->snapshot->snapshotId
            && (string) $row->reconciliation_status === $receipt->status->value
            && (string) $row->freshness === $receipt->freshness->value
            && (bool) $row->source_publishable === $receipt->sourcePublishable
            && (bool) $row->policy_publishable === $receipt->policyPublishable
            && (int) $row->source_record_count === $receipt->sourceRecordCount
            && (int) $row->imported_record_count === $receipt->importedRecordCount
            && (int) $row->excluded_count === $receipt->excludedCount
            && (int) $row->conflict_count === $receipt->conflictCount
            && (int) $row->discrepancy_count === $receipt->discrepancyCount
            && $this->jsonMatches($row->source_totals_json, $receipt->sourceTotals)
            && $this->jsonMatches($row->imported_totals_json, $receipt->importedTotals)
            && $this->jsonMatches($row->discrepancies_json, $receipt->discrepancies)
            && $this->planner->instant($this->date($row->source_as_of_at))
                === $this->planner->instant($receipt->sourceAsOfAt)
            && $this->planner->instant($this->date($row->generated_at))
                === $this->planner->instant($receipt->generatedAt);
    }

    private function persistedReceiptRowMatches(
        object $row,
        FinancialSnapshotBatchDTO $batch,
        FinancialAppendResultDTO $result,
    ): bool {
        return $result->persistenceReceiptId !== null
            && (string) $row->persistence_receipt_id === $result->persistenceReceiptId
            && (string) $row->append_classification === $result->classification->value
            && (bool) $row->promotion_eligible === $result->promotionEligible
            && $this->sourceReceiptRowMatches($row, $batch)
            && $this->planner->instant($this->date($row->recorded_at))
                === $this->planner->instant($batch->snapshot->importedAt);
    }

    private function jsonMatches(mixed $stored, array $expected): bool
    {
        return $this->planner->digest($this->decodedJson($stored, 'financial JSON'))
            === $this->planner->digest($expected);
    }

    private function mappingAttributes(FinancialSourceMappingRevisionDTO $mapping): array
    {
        return [
            'mapping_revision_id' => $mapping->mappingRevisionId,
            'mapping_key' => $mapping->mappingKey,
            'financial_scope_id' => $mapping->financialScopeId,
            'revision_number' => $mapping->revisionNumber,
            'source_system' => $mapping->sourceSystem->value,
            'source_namespace' => trim($mapping->sourceNamespace),
            'source_object_kind' => $mapping->sourceObjectKind->value,
            'source_object_id' => trim($mapping->sourceObjectId),
            'disposition' => $mapping->disposition->value,
            'supersedes_mapping_revision_id' => $mapping->supersedesMappingRevisionId,
            'content_fingerprint' => $mapping->contentFingerprint,
            'effective_at' => $this->planner->instant($mapping->effectiveAt),
            'recorded_at' => $this->planner->instant($mapping->recordedAt),
        ];
    }

    private function snapshotAttributes(FinancialSnapshotDTO $snapshot): array
    {
        return [
            'snapshot_id' => $snapshot->snapshotId,
            'stream_key' => $snapshot->streamKey,
            'source_version_key' => $snapshot->sourceVersionKey,
            'financial_scope_id' => $snapshot->financialScopeId,
            'snapshot_kind' => $snapshot->snapshotKind->value,
            'source_system' => $snapshot->sourceSystem->value,
            'source_namespace' => trim($snapshot->sourceNamespace),
            'adapter_version' => trim($snapshot->adapterVersion),
            'source_as_of_at' => $this->planner->instant($snapshot->sourceAsOfAt),
            'imported_at' => $this->planner->instant($snapshot->importedAt),
            'policy_version' => $snapshot->policyVersion,
            'content_fingerprint' => $snapshot->contentFingerprint,
            'reconciliation_status' => $snapshot->status->value,
            'source_publishable' => $snapshot->sourcePublishable,
            'policy_publishable' => $snapshot->policyPublishable,
            'record_count' => $snapshot->recordCount,
            'summary_json' => $this->json($snapshot->summary),
            'recorded_at' => $this->planner->instant($snapshot->importedAt),
        ];
    }

    private function recordAttributes(
        FinancialSnapshotRecordDTO $record,
        DateTimeInterface $recordedAt,
    ): array {
        return [
            'snapshot_record_id' => $record->snapshotRecordId,
            'snapshot_id' => $record->snapshotId,
            'record_ordinal' => $record->recordOrdinal,
            'mapping_revision_id' => $record->mappingRevisionId,
            'source_identity_key' => $record->sourceIdentityKey,
            'content_fingerprint' => $record->contentFingerprint,
            'provider_status' => $record->providerStatus,
            'financial_status' => $record->financialStatus,
            'recognition_disposition' => $record->recognitionDisposition,
            'source_completeness_status' => $record->sourceCompletenessStatus,
            'source_method' => $record->sourceMethod,
            'currency' => $record->currency,
            'quantity' => $record->quantity,
            'gross_cents' => $record->grossCents,
            'processor_fee_cents' => $record->processorFeeCents,
            'processor_fee_refund_cents' => $record->processorFeeRefundCents,
            'processor_fee_provenance' => $record->processorFeeProvenance,
            'platform_fee_cents' => $record->platformFeeCents,
            'platform_fee_refund_cents' => $record->platformFeeRefundCents,
            'platform_fee_provenance' => $record->platformFeeProvenance,
            'refund_cents' => $record->refundCents,
            'payment_reversal_cents' => $record->paymentReversalCents,
            'dispute_fee_cents' => $record->disputeFeeCents,
            'provider_net_cents' => $record->providerNetCents,
            'net_settlement_cents' => $record->netSettlementCents,
            'settlement_semantic_status' => $record->settlementSemanticStatus,
            'source_occurred_at' => $this->planner->instant($record->sourceOccurredAt),
            'source_updated_at' => $this->planner->instant($record->sourceUpdatedAt),
            'recorded_at' => $this->planner->instant($recordedAt),
        ];
    }

    private function planAttributes(
        FinancialPlanRevisionDTO $plan,
        DateTimeInterface $recordedAt,
    ): array {
        return [
            'plan_revision_id' => $plan->planRevisionId,
            'snapshot_id' => $plan->snapshotId,
            'mapping_revision_id' => $plan->mappingRevisionId,
            'source_identity_key' => $plan->sourceIdentityKey,
            'content_fingerprint' => $plan->contentFingerprint,
            'as_of_at' => $this->planner->instant($plan->asOfAt),
            'pricing_convention' => $plan->pricingConvention,
            'basis_point_rounding' => $plan->basisPointRounding,
            'ticket_customer_price_cents' => $plan->ticketCustomerPriceCents,
            'ticket_quantity' => $plan->ticketQuantity,
            'per_ticket_commission_cents' => $plan->perTicketCommissionCents,
            'fundraising_goal_cents' => $plan->fundraisingGoalCents,
            'university_allocation_basis_points' => $plan->universityAllocationBasisPoints,
            'donorbox_fee_basis_points' => $plan->donorboxFeeBasisPoints,
            'planned_ticket_customer_charge_cents' => $plan->plannedTicketCustomerChargeCents,
            'planned_commission_cents' => $plan->plannedCommissionCents,
            'planned_ticket_proceeds_cents' => $plan->plannedTicketProceedsCents,
            'planned_university_fundraising_allocation_cents' => $plan->plannedUniversityFundraisingAllocationCents,
            'planned_donorbox_fee_cents' => $plan->plannedDonorboxFeeCents,
            'planned_gross_income_cents' => $plan->plannedGrossIncomeCents,
            'planned_income_after_donorbox_fee_cents' => $plan->plannedIncomeAfterDonorboxFeeCents,
            'recorded_at' => $this->planner->instant($recordedAt),
        ];
    }

    private function receiptAttributes(FinancialPersistedReceiptDTO $receipt): array
    {
        return [
            'persistence_receipt_id' => $receipt->persistenceReceiptId,
            'source_receipt_id' => $receipt->sourceReceiptId,
            'snapshot_id' => $receipt->snapshotId,
            'append_classification' => $receipt->appendClassification->value,
            'reconciliation_status' => $receipt->status->value,
            'freshness' => $receipt->freshness->value,
            'source_publishable' => $receipt->sourcePublishable,
            'policy_publishable' => $receipt->policyPublishable,
            'promotion_eligible' => $receipt->promotionEligible,
            'source_record_count' => $receipt->sourceRecordCount,
            'imported_record_count' => $receipt->importedRecordCount,
            'excluded_count' => $receipt->excludedCount,
            'conflict_count' => $receipt->conflictCount,
            'discrepancy_count' => $receipt->discrepancyCount,
            'source_totals_json' => $this->json($receipt->sourceTotals),
            'imported_totals_json' => $this->json($receipt->importedTotals),
            'discrepancies_json' => $this->json($receipt->discrepancies),
            'source_as_of_at' => $this->planner->instant($receipt->sourceAsOfAt),
            'generated_at' => $this->planner->instant($receipt->generatedAt),
            'recorded_at' => $this->planner->instant($receipt->recordedAt),
        ];
    }

    private function mappingFromRow(object $row): FinancialSourceMappingRevisionDTO
    {
        return new FinancialSourceMappingRevisionDTO(
            (string) $row->mapping_revision_id,
            (string) $row->mapping_key,
            (int) $row->financial_scope_id,
            (string) $row->scope_key,
            (string) $row->scope_university_id,
            (string) $row->scope_cycle_id,
            (int) $row->revision_number,
            FinancialSourceSystem::from((string) $row->source_system),
            (string) $row->source_namespace,
            FinancialSourceObjectKind::from((string) $row->source_object_kind),
            (string) $row->source_object_id,
            FinancialMappingDisposition::from((string) $row->disposition),
            $row->supersedes_mapping_revision_id === null
                ? null
                : (string) $row->supersedes_mapping_revision_id,
            (string) $row->content_fingerprint,
            $this->date($row->effective_at),
            $this->date($row->recorded_at),
        );
    }

    private function snapshotFromRow(object $row): FinancialSnapshotDTO
    {
        return new FinancialSnapshotDTO(
            (string) $row->snapshot_id,
            (string) $row->stream_key,
            (string) $row->source_version_key,
            (int) $row->financial_scope_id,
            (string) $row->scope_key,
            (string) $row->scope_university_id,
            (string) $row->scope_cycle_id,
            FinancialSnapshotKind::from((string) $row->snapshot_kind),
            FinancialSourceSystem::from((string) $row->source_system),
            (string) $row->source_namespace,
            (string) $row->adapter_version,
            $this->date($row->source_as_of_at),
            $this->date($row->imported_at),
            $row->policy_version === null ? null : (string) $row->policy_version,
            (string) $row->content_fingerprint,
            FinancialReconciliationStatus::from((string) $row->reconciliation_status),
            (bool) $row->source_publishable,
            (bool) $row->policy_publishable,
            (int) $row->record_count,
            $this->decodedJson($row->summary_json, 'snapshot.summary_json'),
        );
    }

    private function recordFromRow(object $row): FinancialSnapshotRecordDTO
    {
        return new FinancialSnapshotRecordDTO(
            (string) $row->snapshot_record_id,
            (string) $row->snapshot_id,
            (int) $row->record_ordinal,
            (string) $row->mapping_revision_id,
            (string) $row->source_identity_key,
            (string) $row->content_fingerprint,
            (string) $row->provider_status,
            (string) $row->financial_status,
            $this->nullableString($row->recognition_disposition),
            $this->nullableString($row->source_completeness_status),
            $this->nullableString($row->source_method),
            (string) $row->currency,
            (int) $row->quantity,
            $this->nullableInt($row->gross_cents),
            $this->nullableInt($row->processor_fee_cents),
            $this->nullableInt($row->processor_fee_refund_cents),
            $this->nullableString($row->processor_fee_provenance),
            $this->nullableInt($row->platform_fee_cents),
            $this->nullableInt($row->platform_fee_refund_cents),
            $this->nullableString($row->platform_fee_provenance),
            $this->nullableInt($row->refund_cents),
            $this->nullableInt($row->payment_reversal_cents),
            $this->nullableInt($row->dispute_fee_cents),
            $this->nullableInt($row->provider_net_cents),
            $this->nullableInt($row->net_settlement_cents),
            $this->nullableString($row->settlement_semantic_status),
            $this->date($row->source_occurred_at),
            $this->date($row->source_updated_at),
        );
    }

    private function planFromRow(object $row): FinancialPlanRevisionDTO
    {
        return new FinancialPlanRevisionDTO(
            (string) $row->plan_revision_id,
            (string) $row->snapshot_id,
            (string) $row->mapping_revision_id,
            (string) $row->source_identity_key,
            (string) $row->content_fingerprint,
            $this->date($row->as_of_at),
            (string) $row->pricing_convention,
            (string) $row->basis_point_rounding,
            (int) $row->ticket_customer_price_cents,
            (int) $row->ticket_quantity,
            (int) $row->per_ticket_commission_cents,
            (int) $row->fundraising_goal_cents,
            (int) $row->university_allocation_basis_points,
            (int) $row->donorbox_fee_basis_points,
            (int) $row->planned_ticket_customer_charge_cents,
            (int) $row->planned_commission_cents,
            (int) $row->planned_ticket_proceeds_cents,
            (int) $row->planned_university_fundraising_allocation_cents,
            (int) $row->planned_donorbox_fee_cents,
            (int) $row->planned_gross_income_cents,
            (int) $row->planned_income_after_donorbox_fee_cents,
        );
    }

    private function receiptFromRow(object $row): FinancialPersistedReceiptDTO
    {
        return new FinancialPersistedReceiptDTO(
            (string) $row->persistence_receipt_id,
            (string) $row->source_receipt_id,
            (string) $row->snapshot_id,
            FinancialAppendClassification::from((string) $row->append_classification),
            FinancialReconciliationStatus::from((string) $row->reconciliation_status),
            FinancialFreshness::from((string) $row->freshness),
            (bool) $row->source_publishable,
            (bool) $row->policy_publishable,
            (bool) $row->promotion_eligible,
            (int) $row->source_record_count,
            (int) $row->imported_record_count,
            (int) $row->excluded_count,
            (int) $row->conflict_count,
            (int) $row->discrepancy_count,
            $this->decodedJson($row->source_totals_json, 'receipt.source_totals_json'),
            $this->decodedJson($row->imported_totals_json, 'receipt.imported_totals_json'),
            $this->decodedJson($row->discrepancies_json, 'receipt.discrepancies_json'),
            $this->date($row->source_as_of_at),
            $this->date($row->generated_at),
            $this->date($row->recorded_at),
        );
    }

    private function applyExactScopeQuery(
        Builder $builder,
        FinancialSnapshotQueryDTO $query,
        string $scopePrefix = '',
        string $snapshotPrefix = '',
    ): Builder {
        $scope = $scopePrefix === '' ? '' : "$scopePrefix.";
        $snapshot = $snapshotPrefix === '' ? '' : "$snapshotPrefix.";

        return $builder
            ->where($scope.'scope_key', $query->scopeKey)
            ->where($scope.'account_id', $query->accountId)
            ->where($scope.'organizer_id', $query->organizerId)
            ->where($scope.'event_id', $query->eventId)
            ->where($scope.'university_id', trim($query->universityId))
            ->where($scope.'cycle_id', trim($query->cycleId))
            ->where($snapshot.'snapshot_kind', $query->snapshotKind->value)
            ->where($snapshot.'source_namespace', trim($query->sourceNamespace));
    }

    private function validateQuery(FinancialSnapshotQueryDTO $query): void
    {
        $this->assertDigest($query->scopeKey, 'query.scopeKey');
        foreach (['accountId', 'organizerId', 'eventId'] as $field) {
            if ($query->{$field} < 1) {
                throw new InvalidArgumentException("query.$field must be positive.");
            }
        }
        foreach (['universityId', 'cycleId', 'sourceNamespace'] as $field) {
            if (trim($query->{$field}) === '') {
                throw new InvalidArgumentException("query.$field must be non-empty.");
            }
        }
    }

    private function scopeContentMatches(object $row, FinancialScopeDTO $scope): bool
    {
        return (string) $row->scope_key === $scope->scopeKey
            && (int) $row->account_id === $scope->accountId
            && (int) $row->organizer_id === $scope->organizerId
            && (int) $row->event_id === $scope->eventId
            && (string) $row->university_id === trim($scope->universityId)
            && (string) $row->cycle_id === trim($scope->cycleId)
            && (string) $row->timezone === $scope->timezone
            && (string) $row->currency === $scope->currency;
    }

    private function scopeRowMatches(object $row, FinancialScopeDTO $scope): bool
    {
        return $this->scopeContentMatches($row, $scope)
            && $this->planner->instant($this->date($row->recorded_at))
                === $this->planner->instant($scope->recordedAt);
    }

    private function appendResult(
        string $operation,
        FinancialAppendClassification $classification,
        bool $appended,
        bool $appendedReceipt,
        bool $promotionEligible,
        ?string $scopeKey = null,
        ?string $mappingRevisionId = null,
        ?string $snapshotId = null,
        ?string $persistenceReceiptId = null,
    ): FinancialAppendResultDTO {
        return new FinancialAppendResultDTO(
            $operation,
            $classification,
            $appended,
            $appendedReceipt,
            $promotionEligible,
            $scopeKey,
            $mappingRevisionId,
            $snapshotId,
            $persistenceReceiptId,
            0,
            'pending',
        );
    }

    private function withOutcome(
        FinancialAppendResultDTO $result,
        int $attempts,
        string $commitOutcome,
    ): FinancialAppendResultDTO {
        return new FinancialAppendResultDTO(
            $result->operation,
            $result->classification,
            $result->appended,
            $result->appendedReceipt,
            $result->promotionEligible,
            $result->scopeKey,
            $result->mappingRevisionId,
            $result->snapshotId,
            $result->persistenceReceiptId,
            $attempts,
            $commitOutcome,
        );
    }

    private function assertPostgres(Connection $connection): void
    {
        if ($connection->getDriverName() !== 'pgsql') {
            throw new LogicException('Financial persistence requires PostgreSQL.');
        }
    }

    private function isRetryable(Throwable $exception): bool
    {
        $codes = [
            (string) $exception->getCode(),
            (string) ($exception->getPrevious()?->getCode() ?? ''),
        ];
        $previous = $exception->getPrevious();
        if ($previous !== null && property_exists($previous, 'errorInfo')) {
            $errorInfo = $previous->errorInfo;
            if (is_array($errorInfo) && isset($errorInfo[0])) {
                $codes[] = (string) $errorInfo[0];
            }
        }

        return array_intersect(self::RETRYABLE_DATABASE_CODES, $codes) !== [];
    }

    private function safeRollback(Connection $connection): void
    {
        try {
            if ($connection->transactionLevel() > 0) {
                $connection->rollBack();
            }
        } catch (Throwable) {
            // The original transaction failure remains authoritative.
        }
    }

    private function date(mixed $value): DateTimeImmutable
    {
        return $value instanceof DateTimeInterface
            ? DateTimeImmutable::createFromInterface($value)
            : new DateTimeImmutable((string) $value);
    }

    private function nullableInt(mixed $value): ?int
    {
        return $value === null ? null : (int) $value;
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function json(array $value): string
    {
        try {
            return json_encode(
                $value,
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            );
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Financial JSON payload cannot be encoded.', 0, $exception);
        }
    }

    private function decodedJson(mixed $value, string $fieldName): array
    {
        if (is_array($value)) {
            return $value;
        }
        try {
            $decoded = json_decode((string) $value, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new FinancialPersistenceConflictException(
                "$fieldName contains invalid JSON.",
                0,
                $exception,
            );
        }
        if (! is_array($decoded)) {
            throw new FinancialPersistenceConflictException("$fieldName must decode to an array.");
        }

        return $decoded;
    }

    private function assertDigest(string $value, string $fieldName): void
    {
        if (preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException("$fieldName must be a lowercase SHA-256 digest.");
        }
    }
}
