<?php

declare(strict_types=1);

namespace Tests\Feature\Repository;

use DateTimeImmutable;
use HiEvents\DomainObjects\Enums\FinancialAppendClassification;
use HiEvents\DomainObjects\Enums\FinancialSnapshotKind;
use HiEvents\DomainObjects\Enums\FinancialSourceObjectKind;
use HiEvents\DomainObjects\Enums\FinancialSourceSystem;
use HiEvents\DomainObjects\Status\FinancialFreshness;
use HiEvents\DomainObjects\Status\FinancialMappingDisposition;
use HiEvents\DomainObjects\Status\FinancialReconciliationStatus;
use HiEvents\Exceptions\FinancialCommitOutcomeUnknownException;
use HiEvents\Exceptions\FinancialPersistenceConflictException;
use HiEvents\Repository\Eloquent\FinancialPersistenceRepository;
use HiEvents\Repository\Interfaces\FinancialPersistenceRepositoryInterface;
use HiEvents\Services\Domain\Financial\DTOs\FinancialPlanRevisionDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialReconciliationReceiptDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialScopeDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSnapshotBatchDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSnapshotDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSnapshotQueryDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSnapshotRecordDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSourceMappingRevisionDTO;
use HiEvents\Services\Domain\Financial\FinancialPersistencePlanner;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\QueryException;
use RuntimeException;
use Tests\TestCase;
use Throwable;

class FinancialPersistenceRepositoryPostgresTest extends TestCase
{
    private FinancialPersistencePlanner $planner;

    private FinancialPersistenceRepositoryInterface $repository;

    private DatabaseManager $db;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('FINANCIAL_PERSISTENCE_POSTGRES') !== '1') {
            self::markTestSkipped('Requires an explicitly provisioned disposable PostgreSQL database.');
        }
        $this->planner = $this->app->make(FinancialPersistencePlanner::class);
        $this->repository = $this->app->make(FinancialPersistenceRepositoryInterface::class);
        $this->db = $this->app->make(DatabaseManager::class);
        self::assertSame('pgsql', $this->db->connection()->getDriverName());
    }

    public function test_aggregate_append_classification_exact_reads_and_append_only_guards(): void
    {
        $scope = $this->createScope('aggregate');
        $scopeResult = $this->repository->appendScope($scope);
        self::assertSame(FinancialAppendClassification::NEW_SCOPE, $scopeResult->classification);
        self::assertTrue($scopeResult->appended);
        self::assertSame(
            FinancialAppendClassification::UNCHANGED_REPLAY,
            $this->repository->appendScope($scope)->classification,
        );

        $mapping = $this->mapping($scope);
        self::assertSame(
            FinancialAppendClassification::NEW_MAPPING,
            $this->repository->appendMappingRevision($mapping)->classification,
        );
        self::assertSame(
            FinancialAppendClassification::UNCHANGED_REPLAY,
            $this->repository->appendMappingRevision($mapping)->classification,
        );

        $first = $this->planBatch($scope, $mapping);
        $firstResult = $this->repository->appendSnapshotBatch($first);
        self::assertSame(FinancialAppendClassification::NEW_SNAPSHOT, $firstResult->classification);
        self::assertTrue($firstResult->promotionEligible);
        $unchangedReplay = $this->repository->appendSnapshotBatch($first);
        self::assertSame(
            FinancialAppendClassification::UNCHANGED_REPLAY,
            $unchangedReplay->classification,
        );
        self::assertFalse($unchangedReplay->appendedReceipt);

        $mismatchedSnapshot = new FinancialSnapshotDTO(
            $first->snapshot->snapshotId,
            $first->snapshot->streamKey,
            $first->snapshot->sourceVersionKey,
            $first->snapshot->financialScopeId,
            $first->snapshot->scopeKey,
            $first->snapshot->universityId,
            $first->snapshot->cycleId,
            $first->snapshot->snapshotKind,
            $first->snapshot->sourceSystem,
            $first->snapshot->sourceNamespace,
            $first->snapshot->adapterVersion,
            $first->snapshot->sourceAsOfAt,
            $first->snapshot->importedAt,
            $first->snapshot->policyVersion,
            $first->snapshot->contentFingerprint,
            $first->snapshot->status,
            $first->snapshot->sourcePublishable,
            $first->snapshot->policyPublishable,
            $first->snapshot->recordCount,
            [...$first->snapshot->summary, 'plannedGrossIncomeCents' => 15_350_001],
        );
        try {
            $this->repository->appendSnapshotBatch(new FinancialSnapshotBatchDTO(
                $mismatchedSnapshot,
                $first->records,
                $first->planRevision,
                $first->receipt,
            ));
            self::fail('Same snapshot ID accepted different immutable content.');
        } catch (FinancialPersistenceConflictException $exception) {
            self::assertStringContainsString('different immutable content', $exception->getMessage());
        }

        $reusedReceipt = new FinancialReconciliationReceiptDTO(
            $first->receipt->sourceReceiptId,
            $first->receipt->snapshotId,
            $first->receipt->status,
            $first->receipt->freshness,
            $first->receipt->sourcePublishable,
            $first->receipt->policyPublishable,
            $first->receipt->sourceRecordCount,
            $first->receipt->importedRecordCount,
            $first->receipt->excludedCount,
            $first->receipt->conflictCount,
            $first->receipt->discrepancyCount,
            [...$first->receipt->sourceTotals, 'provenance' => 'api'],
            $first->receipt->importedTotals,
            $first->receipt->discrepancies,
            $first->receipt->sourceAsOfAt,
            $first->receipt->generatedAt,
        );
        try {
            $this->repository->appendSnapshotBatch(new FinancialSnapshotBatchDTO(
                $first->snapshot,
                $first->records,
                $first->planRevision,
                $reusedReceipt,
            ));
            self::fail('Reused source receipt ID accepted different immutable content.');
        } catch (FinancialPersistenceConflictException $exception) {
            self::assertStringContainsString('source receipt ID', $exception->getMessage());
        }

        $crossSnapshotReceiptCollision = $this->planBatch(
            $scope,
            $mapping,
            sourceAsOfAt: '2026-07-27T00:00:00Z',
            importedAt: '2026-07-27T00:01:00Z',
            contentSeed: 'cross-snapshot-receipt-collision',
            sourceReceiptSeed: 'receipt-v1',
        );
        try {
            $this->repository->appendSnapshotBatch($crossSnapshotReceiptCollision);
            self::fail('Source receipt ID was reused across snapshots.');
        } catch (FinancialPersistenceConflictException $exception) {
            self::assertStringContainsString('source receipt ID', $exception->getMessage());
        }

        $receiptOnly = $this->planBatch(
            $scope,
            $mapping,
            sourceReceiptSeed: 'receipt-only',
        );
        $receiptResult = $this->repository->appendSnapshotBatch($receiptOnly);
        self::assertSame(FinancialAppendClassification::RECEIPT_ONLY, $receiptResult->classification);
        self::assertFalse($receiptResult->appended);
        self::assertTrue($receiptResult->appendedReceipt);

        $conflict = $this->planBatch(
            $scope,
            $mapping,
            contentSeed: 'conflicting-content',
            sourceReceiptSeed: 'conflict-receipt',
        );
        $conflictResult = $this->repository->appendSnapshotBatch($conflict);
        self::assertSame(FinancialAppendClassification::CONTENT_CONFLICT, $conflictResult->classification);
        self::assertTrue($conflictResult->appended);
        self::assertFalse($conflictResult->promotionEligible);
        self::assertSame(
            $first->snapshot->snapshotId,
            $this->repository->getLatestSourceControlled($this->query($scope))?->snapshot->snapshotId,
        );

        $newer = $this->planBatch(
            $scope,
            $mapping,
            sourceAsOfAt: '2026-07-26T00:00:00Z',
            importedAt: '2026-07-26T00:01:00Z',
            contentSeed: 'newer-content',
            sourceReceiptSeed: 'newer-receipt',
        );
        self::assertSame(
            FinancialAppendClassification::NEW_REVISION,
            $this->repository->appendSnapshotBatch($newer)->classification,
        );

        $stale = $this->planBatch(
            $scope,
            $mapping,
            sourceAsOfAt: '2026-07-24T00:00:00Z',
            importedAt: '2026-07-24T00:01:00Z',
            contentSeed: 'stale-content',
            sourceReceiptSeed: 'stale-receipt',
        );
        $staleResult = $this->repository->appendSnapshotBatch($stale);
        self::assertSame(FinancialAppendClassification::STALE_SNAPSHOT, $staleResult->classification);
        self::assertFalse($staleResult->promotionEligible);

        $query = $this->query($scope);
        $latest = $this->repository->getLatestPromotable($query);
        self::assertNotNull($latest);
        self::assertSame($newer->snapshot->snapshotId, $latest->snapshot->snapshotId);
        self::assertSame($scope->scopeKey, $latest->snapshot->scopeKey);
        self::assertSame($newer->planRevision?->planRevisionId, $latest->planRevision?->planRevisionId);
        self::assertTrue($latest->receipt?->promotionEligible);

        $sourceControlled = $this->repository->getLatestSourceControlled($query);
        self::assertSame($newer->snapshot->snapshotId, $sourceControlled?->snapshot->snapshotId);
        self::assertSame(
            $newer->snapshot->snapshotId,
            $this->repository->getSnapshotById($newer->snapshot->snapshotId, $query)?->snapshot->snapshotId,
        );

        $wrongEvent = new FinancialSnapshotQueryDTO(
            $scope->scopeKey,
            $scope->accountId,
            $scope->organizerId,
            $scope->eventId + 1,
            $scope->universityId,
            $scope->cycleId,
            FinancialSnapshotKind::PLANNED_POSITION,
            'gcu_budget_2026',
        );
        self::assertNull($this->repository->getLatestPromotable($wrongEvent));

        try {
            $this->db->connection()->table('financial_snapshots')
                ->where('snapshot_id', $newer->snapshot->snapshotId)
                ->update(['policy_version' => 'mutated']);
            self::fail('Append-only history accepted an UPDATE.');
        } catch (QueryException $exception) {
            self::assertStringContainsString('append only', $exception->getMessage());
        }

        $scopeMismatch = new FinancialScopeDTO(
            $scope->scopeKey,
            $scope->accountId,
            $scope->organizerId,
            $scope->eventId,
            $scope->universityId,
            $scope->cycleId,
            $scope->timezone,
            'CAD',
            $scope->recordedAt,
        );
        $this->expectException(FinancialPersistenceConflictException::class);
        $this->repository->appendScope($scopeMismatch);
    }

    public function test_non_plan_records_are_appended_and_hydrated_through_exact_scope_reads(): void
    {
        $scope = $this->createScope('records');
        $this->repository->appendScope($scope);
        $mapping = $this->ticketMapping($scope);
        $this->repository->appendMappingRevision($mapping);
        $batch = $this->sparkBatch($scope, $mapping);

        $result = $this->repository->appendSnapshotBatch($batch);
        self::assertSame(FinancialAppendClassification::NEW_SNAPSHOT, $result->classification);
        self::assertTrue($result->promotionEligible);
        self::assertSame(
            FinancialAppendClassification::UNCHANGED_REPLAY,
            $this->repository->appendSnapshotBatch($batch)->classification,
        );

        $query = new FinancialSnapshotQueryDTO(
            $scope->scopeKey,
            $scope->accountId,
            $scope->organizerId,
            $scope->eventId,
            $scope->universityId,
            $scope->cycleId,
            FinancialSnapshotKind::SPARK_TICKET,
            'spark_gcu_2026',
        );
        $persisted = $this->repository->getLatestPromotable($query);
        self::assertNotNull($persisted);
        self::assertCount(1, $persisted->records);
        self::assertSame(5_500, $persisted->records[0]->grossCents);
        self::assertSame(600, $persisted->records[0]->platformFeeCents);
        self::assertSame('estimated', $persisted->records[0]->processorFeeProvenance);
        self::assertNull($persisted->planRevision);
        self::assertSame($batch->receipt->sourceReceiptId, $persisted->receipt?->sourceReceiptId);
    }

    public function test_database_receipt_metric_contract_matches_typed_privacy_validation(): void
    {
        self::assertTrue($this->databaseMetricSafe([
            'currency' => 'USD',
            'provenance' => 'api',
            'includedProviderStatuses' => ['Paid', 'Waiting approval'],
            'grossCents' => null,
        ]));
        self::assertFalse($this->databaseMetricSafe(['provenance' => null]));
        self::assertFalse($this->databaseMetricSafe(['includedProviderStatuses' => [123]]));
        self::assertFalse($this->databaseMetricSafe(['includedProviderStatuses' => ['john.smith']]));
        self::assertFalse($this->databaseMetricSafe(['grossCents' => 9_007_199_254_740_992]));
        self::assertFalse($this->databaseMetricSafe(['grossCents' => 1.5]));
        self::assertTrue($this->databaseMetricSafe(['provenance' => 'csv_export']));
    }

    public function test_uncertain_commit_uses_full_readback_and_never_retries_an_unknown_absent_write(): void
    {
        $scope = $this->createScope('commit-after');
        $this->repository->appendScope($scope);
        $mapping = $this->mapping($scope);
        $this->repository->appendMappingRevision($mapping);
        $batch = $this->planBatch(
            $scope,
            $mapping,
            contentSeed: 'commit-after-content',
            sourceReceiptSeed: 'commit-after-receipt',
        );

        $confirmed = $this->faultingRepository(true)->appendSnapshotBatch($batch);
        self::assertSame('confirmed_after_uncertain_commit', $confirmed->commitOutcome);
        self::assertSame(1, $confirmed->attempts);
        self::assertEquals(
            $batch->snapshot->summary,
            $this->repository->getSnapshotById(
                $batch->snapshot->snapshotId,
                $this->query($scope),
            )?->snapshot->summary,
        );

        $absentScope = $this->createScope('commit-before');
        try {
            $this->faultingRepository(false)->appendScope($absentScope);
            self::fail('An absent write with an uncertain commit was retried or acknowledged.');
        } catch (FinancialCommitOutcomeUnknownException $exception) {
            self::assertStringContainsString('absent on readback', $exception->getMessage());
        }
        self::assertSame(
            0,
            $this->db->connection()->table('financial_scopes')
                ->where('scope_key', $absentScope->scopeKey)
                ->count(),
        );
    }

    public function test_forced_overlapping_serializable_appends_converge_for_plan_and_record_batches(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the PostgreSQL concurrency smoke.');
        }

        $planScope = $this->createScope('concurrent-plan');
        $this->repository->appendScope($planScope);
        $planMapping = $this->mapping($planScope);
        $this->repository->appendMappingRevision($planMapping);
        $planBatch = $this->planBatch(
            $planScope,
            $planMapping,
            contentSeed: 'concurrent-plan-content',
            sourceReceiptSeed: 'concurrent-plan-receipt',
        );
        $planPayloads = $this->runForcedConcurrentBatches([$planBatch, $planBatch]);
        $this->assertConvergedReplay($planPayloads, $planBatch);

        $recordScope = $this->createScope('concurrent-record');
        $this->repository->appendScope($recordScope);
        $recordMapping = $this->ticketMapping($recordScope);
        $this->repository->appendMappingRevision($recordMapping);
        $recordBatch = $this->sparkBatch($recordScope, $recordMapping);
        $recordPayloads = $this->runForcedConcurrentBatches([$recordBatch, $recordBatch]);
        $this->assertConvergedReplay($recordPayloads, $recordBatch);
    }

    public function test_forced_overlapping_different_content_quarantines_the_conflict(): void
    {
        if (! function_exists('pcntl_fork')) {
            self::markTestSkipped('pcntl is required for the PostgreSQL concurrency smoke.');
        }

        $scope = $this->createScope('concurrent-conflict');
        $this->repository->appendScope($scope);
        $mapping = $this->mapping($scope);
        $this->repository->appendMappingRevision($mapping);
        $first = $this->planBatch(
            $scope,
            $mapping,
            contentSeed: 'concurrent-content-a',
            sourceReceiptSeed: 'concurrent-receipt-a',
        );
        $second = $this->planBatch(
            $scope,
            $mapping,
            contentSeed: 'concurrent-content-b',
            sourceReceiptSeed: 'concurrent-receipt-b',
        );

        $payloads = $this->runForcedConcurrentBatches([$first, $second]);
        $classifications = array_column($payloads, 'classification');
        sort($classifications);
        self::assertSame(['content_conflict', 'new_snapshot'], $classifications);
        $promotion = array_column($payloads, 'promotionEligible');
        sort($promotion);
        self::assertSame([false, true], $promotion);
        self::assertGreaterThanOrEqual(2, max(array_column($payloads, 'attempts')));
        self::assertSame(
            2,
            $this->db->connection()->table('financial_snapshots')
                ->where('source_version_key', $first->snapshot->sourceVersionKey)
                ->count(),
        );
        self::assertSame(
            1,
            $this->db->connection()->table('financial_reconciliation_receipts')
                ->whereIn('snapshot_id', [$first->snapshot->snapshotId, $second->snapshot->snapshotId])
                ->where('promotion_eligible', true)
                ->count(),
        );
    }

    private function databaseMetricSafe(array $value): bool
    {
        $row = $this->db->connection()->selectOne(
            'SELECT kampy_financial_receipt_metrics_safe(CAST(? AS jsonb)) AS safe',
            [json_encode($value, JSON_THROW_ON_ERROR)],
        );

        return in_array($row->safe, [true, 1, '1', 't'], true);
    }

    private function faultingRepository(bool $commitBeforeThrow): FinancialPersistenceRepository
    {
        return new class($this->db, $this->planner, $commitBeforeThrow) extends FinancialPersistenceRepository
        {
            public function __construct(
                DatabaseManager $db,
                FinancialPersistencePlanner $planner,
                private readonly bool $commitBeforeThrow,
            ) {
                parent::__construct($db, $planner);
            }

            protected function commitTransaction(Connection $connection): void
            {
                if ($this->commitBeforeThrow) {
                    parent::commitTransaction($connection);
                }

                throw new RuntimeException('Simulated lost commit acknowledgement.');
            }
        };
    }

    /**
     * @param  array{FinancialSnapshotBatchDTO, FinancialSnapshotBatchDTO}  $batches
     * @return array{array<string, mixed>, array<string, mixed>}
     */
    private function runForcedConcurrentBatches(array $batches): array
    {
        self::assertCount(2, $batches);
        $token = random_int(1, 2_000_000_000);
        $function = "financial_test_barrier_$token";
        $trigger = "financial_test_barrier_trigger_$token";
        $defaultConnection = $this->db->getDefaultConnection();
        $barrierConnection = "financial_barrier_$token";
        config([
            "database.connections.$barrierConnection" => config("database.connections.$defaultConnection"),
        ]);
        $barrier = $this->db->connection($barrierConnection);
        $barrier->unprepared(<<<SQL
CREATE FUNCTION $function() RETURNS trigger
LANGUAGE plpgsql AS \$\$
BEGIN
  PERFORM pg_advisory_lock($token);
  PERFORM pg_advisory_unlock($token);
  RETURN NEW;
END;
\$\$;
CREATE TRIGGER $trigger
  BEFORE INSERT ON financial_snapshots
  FOR EACH ROW EXECUTE FUNCTION $function();
SQL);
        $barrier->select('SELECT pg_advisory_lock(?)', [$token]);

        $paths = [
            tempnam(sys_get_temp_dir(), 'financial-child-a-'),
            tempnam(sys_get_temp_dir(), 'financial-child-b-'),
        ];
        self::assertNotFalse($paths[0]);
        self::assertNotFalse($paths[1]);
        $this->db->purge($defaultConnection);
        $children = [];
        $barrierReached = false;
        $unlocked = false;

        try {
            foreach ($paths as $index => $path) {
                $pid = pcntl_fork();
                self::assertNotSame(-1, $pid);
                if ($pid === 0) {
                    $payload = [];
                    try {
                        $this->app->make(DatabaseManager::class)->purge($defaultConnection);
                        $result = $this->app->make(FinancialPersistenceRepositoryInterface::class)
                            ->appendSnapshotBatch($batches[$index]);
                        $payload = [
                            'ok' => true,
                            'classification' => $result->classification->value,
                            'promotionEligible' => $result->promotionEligible,
                            'attempts' => $result->attempts,
                        ];
                    } catch (Throwable $exception) {
                        $payload = [
                            'ok' => false,
                            'class' => $exception::class,
                            'message' => $exception->getMessage(),
                        ];
                    }
                    file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
                    exit($payload['ok'] ? 0 : 1);
                }
                $children[] = $pid;
            }

            for ($attempt = 0; $attempt < 250; $attempt++) {
                $waiting = (int) $barrier->table('pg_stat_activity')
                    ->whereRaw('datname = current_database()')
                    ->where('pid', '<>', $barrier->getPdo()->query('SELECT pg_backend_pid()')->fetchColumn())
                    ->where('wait_event', 'advisory')
                    ->count();
                if ($waiting >= 2) {
                    $barrierReached = true;
                    break;
                }
                usleep(20_000);
            }
            $barrier->select('SELECT pg_advisory_unlock(?)', [$token]);
            $unlocked = true;

            $statuses = [];
            foreach ($children as $pid) {
                pcntl_waitpid($pid, $status);
                $statuses[] = pcntl_wexitstatus($status);
            }
            $this->db->purge($defaultConnection);
            $payloads = array_map(
                static fn (string $path): array => json_decode(
                    (string) file_get_contents($path),
                    true,
                    flags: JSON_THROW_ON_ERROR,
                ),
                $paths,
            );
            self::assertTrue($barrierReached, 'Both transactions did not reach the forced overlap barrier.');
            self::assertSame([0, 0], $statuses, json_encode($payloads));
            self::assertTrue($payloads[0]['ok']);
            self::assertTrue($payloads[1]['ok']);

            return $payloads;
        } finally {
            if (! $unlocked) {
                $barrier->select('SELECT pg_advisory_unlock(?)', [$token]);
            }
            foreach ($children as $pid) {
                if (pcntl_waitpid($pid, $status, WNOHANG) === 0) {
                    pcntl_waitpid($pid, $status);
                }
            }
            foreach ($paths as $path) {
                @unlink($path);
            }
            $barrier->unprepared(<<<SQL
DROP TRIGGER IF EXISTS $trigger ON financial_snapshots;
DROP FUNCTION IF EXISTS $function();
SQL);
            $this->db->purge($barrierConnection);
        }
    }

    /** @param array{array<string, mixed>, array<string, mixed>} $payloads */
    private function assertConvergedReplay(
        array $payloads,
        FinancialSnapshotBatchDTO $batch,
    ): void {
        $classifications = array_column($payloads, 'classification');
        sort($classifications);
        self::assertSame(['new_snapshot', 'unchanged_replay'], $classifications);
        self::assertGreaterThanOrEqual(2, max(array_column($payloads, 'attempts')));
        self::assertSame(
            1,
            $this->db->connection()->table('financial_snapshots')
                ->where('snapshot_id', $batch->snapshot->snapshotId)
                ->count(),
        );
        self::assertSame(
            1,
            $this->db->connection()->table('financial_reconciliation_receipts')
                ->where('snapshot_id', $batch->snapshot->snapshotId)
                ->count(),
        );
    }

    private function createScope(string $suffix): FinancialScopeDTO
    {
        $connection = $this->db->connection();
        $shortSuffix = substr(hash('sha256', $suffix), 0, 12);
        $accountId = $connection->table('accounts')->insertGetId([
            'currency_code' => 'USD',
            'timezone' => 'America/Phoenix',
            'created_at' => now(),
            'updated_at' => now(),
            'name' => "Financial $suffix",
            'email' => "$suffix-account@example.invalid",
            'short_id' => "fin-$shortSuffix",
        ]);
        $userId = $connection->table('users')->insertGetId([
            'email' => "$suffix-user@example.invalid",
            'password' => 'not-a-real-password',
            'created_at' => now(),
            'updated_at' => now(),
            'first_name' => 'Invented',
            'timezone' => 'America/Phoenix',
        ]);
        $organizerId = $connection->table('organizers')->insertGetId([
            'account_id' => $accountId,
            'name' => "Financial $suffix Organizer",
            'email' => "$suffix-organizer@example.invalid",
            'created_at' => now(),
            'updated_at' => now(),
            'currency' => 'USD',
            'timezone' => 'America/Phoenix',
        ]);
        $eventId = $connection->table('events')->insertGetId([
            'title' => "Financial $suffix Event",
            'account_id' => $accountId,
            'user_id' => $userId,
            'start_date' => now(),
            'end_date' => now()->addDay(),
            'status' => 'DRAFT',
            'currency' => 'USD',
            'timezone' => 'America/Phoenix',
            'created_at' => now(),
            'updated_at' => now(),
            'organizer_id' => $organizerId,
            'short_id' => "evt-$shortSuffix",
        ]);
        $scopeKey = $this->planner->digest([
            'accountId' => $accountId,
            'organizerId' => $organizerId,
            'eventId' => $eventId,
            'universityId' => 'gcu',
            'cycleId' => '2026-fall',
        ]);
        $scope = new FinancialScopeDTO(
            $scopeKey,
            $accountId,
            $organizerId,
            $eventId,
            'gcu',
            '2026-fall',
            'America/Phoenix',
            'USD',
            new DateTimeImmutable('2026-07-25T00:00:00Z'),
        );

        return $scope;
    }

    private function mapping(FinancialScopeDTO $scope): FinancialSourceMappingRevisionDTO
    {
        $scopeId = (int) $this->db->connection()->table('financial_scopes')
            ->where('scope_key', $scope->scopeKey)
            ->value('id');
        $sourceObjectId = 'gcu-budget-2026-'.substr($scope->scopeKey, 0, 8);
        $source = [
            'system' => 'google_sheet',
            'namespace' => 'gcu_budget_2026',
            'objectKind' => 'plan_record',
            'objectId' => $sourceObjectId,
        ];
        $scopeIdentity = [
            'scopeKey' => $scope->scopeKey,
            'universityId' => $scope->universityId,
            'cycleId' => $scope->cycleId,
        ];
        $mappingKey = $this->planner->digest($source);
        $effectiveAt = new DateTimeImmutable('2026-07-23T00:00:00Z');
        $body = [
            'mappingKey' => $mappingKey,
            'source' => $source,
            'scope' => $scopeIdentity,
            'revisionNumber' => 1,
            'disposition' => 'active',
            'supersedesMappingRevisionId' => null,
            'effectiveAt' => $this->planner->instant($effectiveAt),
        ];

        return new FinancialSourceMappingRevisionDTO(
            $this->planner->digest($body),
            $mappingKey,
            $scopeId,
            $scope->scopeKey,
            $scope->universityId,
            $scope->cycleId,
            1,
            FinancialSourceSystem::GOOGLE_SHEET,
            'gcu_budget_2026',
            FinancialSourceObjectKind::PLAN_RECORD,
            $sourceObjectId,
            FinancialMappingDisposition::ACTIVE,
            null,
            $this->planner->digest([
                'source' => $source,
                'scope' => $scopeIdentity,
                'disposition' => 'active',
                'effectiveAt' => $this->planner->instant($effectiveAt),
            ]),
            $effectiveAt,
            new DateTimeImmutable('2026-07-25T00:01:00Z'),
        );
    }

    private function ticketMapping(FinancialScopeDTO $scope): FinancialSourceMappingRevisionDTO
    {
        $scopeId = (int) $this->db->connection()->table('financial_scopes')
            ->where('scope_key', $scope->scopeKey)
            ->value('id');
        $sourceObjectId = 'spark-event-'.substr($scope->scopeKey, 0, 8);
        $source = [
            'system' => 'spark',
            'namespace' => 'spark_gcu_2026',
            'objectKind' => 'ticket_event',
            'objectId' => $sourceObjectId,
        ];
        $scopeIdentity = [
            'scopeKey' => $scope->scopeKey,
            'universityId' => $scope->universityId,
            'cycleId' => $scope->cycleId,
        ];
        $mappingKey = $this->planner->digest($source);
        $effectiveAt = new DateTimeImmutable('2026-07-23T00:00:00Z');
        $body = [
            'mappingKey' => $mappingKey,
            'source' => $source,
            'scope' => $scopeIdentity,
            'revisionNumber' => 1,
            'disposition' => 'active',
            'supersedesMappingRevisionId' => null,
            'effectiveAt' => $this->planner->instant($effectiveAt),
        ];

        return new FinancialSourceMappingRevisionDTO(
            $this->planner->digest($body),
            $mappingKey,
            $scopeId,
            $scope->scopeKey,
            $scope->universityId,
            $scope->cycleId,
            1,
            FinancialSourceSystem::SPARK,
            'spark_gcu_2026',
            FinancialSourceObjectKind::TICKET_EVENT,
            $sourceObjectId,
            FinancialMappingDisposition::ACTIVE,
            null,
            $this->planner->digest([
                'source' => $source,
                'scope' => $scopeIdentity,
                'disposition' => 'active',
                'effectiveAt' => $this->planner->instant($effectiveAt),
            ]),
            $effectiveAt,
            new DateTimeImmutable('2026-07-25T00:01:00Z'),
        );
    }

    private function sparkBatch(
        FinancialScopeDTO $scope,
        FinancialSourceMappingRevisionDTO $mapping,
    ): FinancialSnapshotBatchDTO {
        $scopeId = (int) $this->db->connection()->table('financial_scopes')
            ->where('scope_key', $scope->scopeKey)
            ->value('id');
        $sourceAsOf = new DateTimeImmutable('2026-07-25T00:00:00Z');
        $imported = new DateTimeImmutable('2026-07-25T00:01:00Z');
        $recordFingerprint = $this->planner->digest(['record' => 'spark-1']);
        $contentFingerprint = $this->planner->digest(['records' => [$recordFingerprint]]);
        $source = [
            'system' => 'spark',
            'namespace' => 'spark_gcu_2026',
            'adapterVersion' => '2026-07-25.1',
        ];
        $scopeIdentity = [
            'scopeKey' => $scope->scopeKey,
            'universityId' => $scope->universityId,
            'cycleId' => $scope->cycleId,
        ];
        $streamKey = $this->planner->digest([
            'snapshotKind' => 'spark_ticket',
            'source' => $source,
            'scope' => $scopeIdentity,
        ]);
        $sourceVersionKey = $this->planner->digest([
            'streamKey' => $streamKey,
            'sourceAsOfAt' => $this->planner->instant($sourceAsOf),
        ]);
        $snapshotId = $this->planner->digest([
            'sourceVersionKey' => $sourceVersionKey,
            'contentFingerprint' => $contentFingerprint,
        ]);
        $snapshot = new FinancialSnapshotDTO(
            $snapshotId,
            $streamKey,
            $sourceVersionKey,
            $scopeId,
            $scope->scopeKey,
            $scope->universityId,
            $scope->cycleId,
            FinancialSnapshotKind::SPARK_TICKET,
            FinancialSourceSystem::SPARK,
            'spark_gcu_2026',
            '2026-07-25.1',
            $sourceAsOf,
            $imported,
            '2026-07-25.2',
            $contentFingerprint,
            FinancialReconciliationStatus::PASS,
            true,
            true,
            1,
            [
                'eligibleTransactionCount' => 1,
                'eligibilityDefinition' => 'paid_positive_price',
                'eligibilitySourceGrain' => 'attendee',
                'zeroPriceReviewCount' => 0,
                'unpaidOrUnsettledCount' => 0,
            ],
        );
        $sourceIdentityKey = $this->planner->digest(['spark' => 'attendee-1']);
        $record = new FinancialSnapshotRecordDTO(
            $this->planner->digest([
                'snapshotId' => $snapshotId,
                'recordOrdinal' => 0,
                'sourceIdentityKey' => $sourceIdentityKey,
            ]),
            $snapshotId,
            0,
            $mapping->mappingRevisionId,
            $sourceIdentityKey,
            $recordFingerprint,
            'paid',
            'paid',
            'recognized_candidate',
            'estimated_fees',
            'spark_attendee_row',
            'USD',
            1,
            5_500,
            190,
            0,
            'estimated',
            600,
            0,
            'estimated',
            0,
            0,
            null,
            null,
            4_710,
            null,
            new DateTimeImmutable('2026-07-24T23:00:00Z'),
            new DateTimeImmutable('2026-07-24T23:30:00Z'),
        );
        $receipt = new FinancialReconciliationReceiptDTO(
            $this->planner->digest([
                'receipt' => 'spark-records',
                'scopeKey' => $scope->scopeKey,
            ]),
            $snapshotId,
            FinancialReconciliationStatus::PASS,
            FinancialFreshness::CURRENT,
            true,
            true,
            1,
            1,
            0,
            0,
            0,
            ['recordCount' => 1, 'customerChargeCents' => 5_500],
            ['recordCount' => 1, 'customerChargeCents' => 5_500],
            [],
            $sourceAsOf,
            $imported,
        );

        return new FinancialSnapshotBatchDTO($snapshot, [$record], null, $receipt);
    }

    private function planBatch(
        FinancialScopeDTO $scope,
        FinancialSourceMappingRevisionDTO $mapping,
        string $sourceAsOfAt = '2026-07-25T00:00:00Z',
        string $importedAt = '2026-07-25T00:01:00Z',
        string $contentSeed = 'plan-v1',
        string $sourceReceiptSeed = 'receipt-v1',
    ): FinancialSnapshotBatchDTO {
        $scopeId = (int) $this->db->connection()->table('financial_scopes')
            ->where('scope_key', $scope->scopeKey)
            ->value('id');
        $sourceAsOf = new DateTimeImmutable($sourceAsOfAt);
        $imported = new DateTimeImmutable($importedAt);
        $contentFingerprint = $this->planner->digest(['content' => $contentSeed]);
        $source = [
            'system' => 'google_sheet',
            'namespace' => 'gcu_budget_2026',
            'adapterVersion' => '2026-07-25.1',
        ];
        $scopeIdentity = [
            'scopeKey' => $scope->scopeKey,
            'universityId' => $scope->universityId,
            'cycleId' => $scope->cycleId,
        ];
        $streamKey = $this->planner->digest([
            'snapshotKind' => 'planned_position',
            'source' => $source,
            'scope' => $scopeIdentity,
        ]);
        $sourceVersionKey = $this->planner->digest([
            'streamKey' => $streamKey,
            'sourceAsOfAt' => $this->planner->instant($sourceAsOf),
        ]);
        $snapshotId = $this->planner->digest([
            'sourceVersionKey' => $sourceVersionKey,
            'contentFingerprint' => $contentFingerprint,
        ]);
        $snapshot = new FinancialSnapshotDTO(
            $snapshotId,
            $streamKey,
            $sourceVersionKey,
            $scopeId,
            $scope->scopeKey,
            $scope->universityId,
            $scope->cycleId,
            FinancialSnapshotKind::PLANNED_POSITION,
            FinancialSourceSystem::GOOGLE_SHEET,
            'gcu_budget_2026',
            '2026-07-25.1',
            $sourceAsOf,
            $imported,
            '2026-07-25.2',
            $contentFingerprint,
            FinancialReconciliationStatus::PASS,
            true,
            true,
            0,
            [
                'plannedTicketProceedsCents' => 7_350_000,
                'plannedFundraisingGoalCents' => 2_000_000,
                'plannedGrossIncomeCents' => 15_350_000,
            ],
        );
        $planBody = [
            'snapshotId' => $snapshotId,
            'mappingRevisionId' => $mapping->mappingRevisionId,
            'sourceIdentityKey' => $this->planner->digest(['source' => 'plan']),
            'contentFingerprint' => $contentFingerprint,
            'asOfAt' => $this->planner->instant($sourceAsOf),
            'pricingConvention' => 'customer_price_less_commission',
            'basisPointRounding' => 'half_up_to_cent',
            'ticketCustomerPriceCents' => 5_500,
            'ticketQuantity' => 1_500,
            'perTicketCommissionCents' => 600,
            'fundraisingGoalCents' => 2_000_000,
            'universityAllocationBasisPoints' => 4_000,
            'donorboxFeeBasisPoints' => 175,
            'plannedTicketCustomerChargeCents' => 8_250_000,
            'plannedCommissionCents' => 900_000,
            'plannedTicketProceedsCents' => 7_350_000,
            'plannedUniversityFundraisingAllocationCents' => 800_000,
            'plannedDonorboxFeeCents' => 14_000,
            'plannedGrossIncomeCents' => 15_350_000,
            'plannedIncomeAfterDonorboxFeeCents' => 15_210_000,
        ];
        $plan = new FinancialPlanRevisionDTO(
            $this->planner->digest($planBody),
            $planBody['snapshotId'],
            $planBody['mappingRevisionId'],
            $planBody['sourceIdentityKey'],
            $planBody['contentFingerprint'],
            $sourceAsOf,
            $planBody['pricingConvention'],
            $planBody['basisPointRounding'],
            $planBody['ticketCustomerPriceCents'],
            $planBody['ticketQuantity'],
            $planBody['perTicketCommissionCents'],
            $planBody['fundraisingGoalCents'],
            $planBody['universityAllocationBasisPoints'],
            $planBody['donorboxFeeBasisPoints'],
            $planBody['plannedTicketCustomerChargeCents'],
            $planBody['plannedCommissionCents'],
            $planBody['plannedTicketProceedsCents'],
            $planBody['plannedUniversityFundraisingAllocationCents'],
            $planBody['plannedDonorboxFeeCents'],
            $planBody['plannedGrossIncomeCents'],
            $planBody['plannedIncomeAfterDonorboxFeeCents'],
        );
        $receipt = new FinancialReconciliationReceiptDTO(
            $this->planner->digest([
                'receipt' => $sourceReceiptSeed,
                'scopeKey' => $scope->scopeKey,
            ]),
            $snapshotId,
            FinancialReconciliationStatus::PASS,
            FinancialFreshness::CURRENT,
            true,
            true,
            1,
            1,
            0,
            0,
            0,
            ['contentFingerprint' => $contentFingerprint],
            ['contentFingerprint' => $contentFingerprint],
            [],
            $sourceAsOf,
            $imported,
        );

        return new FinancialSnapshotBatchDTO($snapshot, [], $plan, $receipt);
    }

    private function query(FinancialScopeDTO $scope): FinancialSnapshotQueryDTO
    {
        return new FinancialSnapshotQueryDTO(
            $scope->scopeKey,
            $scope->accountId,
            $scope->organizerId,
            $scope->eventId,
            $scope->universityId,
            $scope->cycleId,
            FinancialSnapshotKind::PLANNED_POSITION,
            'gcu_budget_2026',
        );
    }
}
