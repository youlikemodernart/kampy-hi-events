<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Financial;

use DateTimeImmutable;
use DateTimeInterface;
use DateTimeZone;
use HiEvents\DomainObjects\Enums\FinancialAppendClassification;
use HiEvents\DomainObjects\Enums\FinancialSnapshotKind;
use HiEvents\DomainObjects\Status\FinancialMappingDisposition;
use HiEvents\DomainObjects\Status\FinancialReconciliationStatus;
use HiEvents\Services\Domain\Financial\DTOs\FinancialMappingAppendPlanDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialPersistedReceiptDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialPlanRevisionDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialReconciliationReceiptDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialScopeDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSnapshotAppendPlanDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSnapshotBatchDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSnapshotDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSnapshotRecordDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSourceMappingRevisionDTO;
use InvalidArgumentException;
use JsonException;

class FinancialPersistencePlanner
{
    private const MAX_SAFE_INTEGER = 9_007_199_254_740_991;

    /** @var array<string, list<string>> */
    private const SUMMARY_KEYS = [
        'planned_position' => [
            'plannedTicketProceedsCents',
            'plannedFundraisingGoalCents',
            'plannedGrossIncomeCents',
        ],
        'spark_ticket' => [
            'eligibleTransactionCount',
            'eligibilityDefinition',
            'eligibilitySourceGrain',
            'zeroPriceReviewCount',
            'unpaidOrUnsettledCount',
        ],
        'stripe_settlement' => [
            'semanticStatus',
            'policyCompatible',
            'connectedNetCents',
            'connectedSettlementAfterAdjustmentsCents',
        ],
        'donorbox' => [
            'controlledRecordCount',
            'controlledGrossCents',
            'incompleteRecordCount',
            'contractStatus',
            'grossControlStatus',
            'netControlStatus',
            'sourceWindowFromAt',
            'sourceTimeZone',
        ],
    ];

    private const RECEIPT_PROVENANCE_VALUES = ['dashboard_display', 'csv_export', 'api'];

    private const RECEIPT_PROVIDER_STATUS_VALUES = [
        'Paid',
        'Refunded',
        'Waiting approval',
        'Pending',
        'Charge pending',
        'Failed',
    ];

    private const RECEIPT_METRIC_KEYS = [
        'contentFingerprint',
        'recordCount',
        'quantity',
        'currency',
        'includedProviderStatuses',
        'provenance',
        'grossCents',
        'amountRefundedCents',
        'platformFeeCents',
        'processorFeeCents',
        'netCents',
        'customerChargeCents',
        'kampProceedsCents',
        'applicationFeeCents',
        'applicationFeeRefundCents',
        'processorFeeRefundCents',
        'refundCents',
        'paymentReversalCents',
        'kampNetSettlementCents',
        'applicationFeeActualCents',
        'applicationFeeEstimatedCents',
        'processorFeeActualCents',
        'processorFeeEstimatedCents',
        'stripeProcessingFeeCents',
        'connectedNetCents',
        'disputeAmountCents',
        'disputeFeeCents',
        'connectedSettlementAfterAdjustmentsCents',
    ];

    public function validateScope(FinancialScopeDTO $scope): void
    {
        $this->assertDigest($scope->scopeKey, 'scope.scopeKey');
        $this->assertPositiveInteger($scope->accountId, 'scope.accountId');
        $this->assertPositiveInteger($scope->organizerId, 'scope.organizerId');
        $this->assertPositiveInteger($scope->eventId, 'scope.eventId');
        $this->assertNonEmpty($scope->universityId, 'scope.universityId');
        $this->assertNonEmpty($scope->cycleId, 'scope.cycleId');
        $this->assertNonEmpty($scope->timezone, 'scope.timezone');
        if (! in_array($scope->timezone, timezone_identifiers_list(), true)) {
            throw new InvalidArgumentException('scope.timezone must be an IANA timezone.');
        }
        $this->assertCurrency($scope->currency, 'scope.currency');

        $expected = $this->scopeKey(
            $scope->accountId,
            $scope->organizerId,
            $scope->eventId,
            $scope->universityId,
            $scope->cycleId,
        );
        if (! hash_equals($expected, $scope->scopeKey)) {
            throw new InvalidArgumentException('scope.scopeKey does not match its deterministic identity.');
        }
    }

    public function validateMapping(FinancialSourceMappingRevisionDTO $mapping): void
    {
        $this->assertPositiveInteger($mapping->financialScopeId, 'mapping.financialScopeId');
        $this->assertDigest($mapping->scopeKey, 'mapping.scopeKey');
        $this->assertPositiveInteger($mapping->revisionNumber, 'mapping.revisionNumber');
        $this->assertNonEmpty($mapping->universityId, 'mapping.universityId');
        $this->assertNonEmpty($mapping->cycleId, 'mapping.cycleId');
        $this->assertNonEmpty($mapping->sourceNamespace, 'mapping.sourceNamespace');
        $this->assertNonEmpty($mapping->sourceObjectId, 'mapping.sourceObjectId');
        $this->assertDigest($mapping->mappingKey, 'mapping.mappingKey');
        $this->assertDigest($mapping->mappingRevisionId, 'mapping.mappingRevisionId');
        $this->assertDigest($mapping->contentFingerprint, 'mapping.contentFingerprint');

        if ($mapping->revisionNumber === 1 && $mapping->supersedesMappingRevisionId !== null) {
            throw new InvalidArgumentException('The first mapping revision cannot supersede another revision.');
        }
        if ($mapping->revisionNumber > 1) {
            $this->assertDigest(
                $mapping->supersedesMappingRevisionId,
                'mapping.supersedesMappingRevisionId',
            );
        }
        if ($this->instant($mapping->recordedAt) < $this->instant($mapping->effectiveAt)) {
            throw new InvalidArgumentException('mapping.recordedAt cannot precede mapping.effectiveAt.');
        }

        $source = $this->mappingSource($mapping);
        $scope = $this->scopeIdentity(
            $mapping->scopeKey,
            $mapping->universityId,
            $mapping->cycleId,
        );
        $expectedMappingKey = $this->digest($source);
        if (! hash_equals($expectedMappingKey, $mapping->mappingKey)) {
            throw new InvalidArgumentException('mapping.mappingKey does not match its deterministic identity.');
        }

        $body = [
            'mappingKey' => $mapping->mappingKey,
            'source' => $source,
            'scope' => $scope,
            'revisionNumber' => $mapping->revisionNumber,
            'disposition' => $mapping->disposition->value,
            'supersedesMappingRevisionId' => $mapping->supersedesMappingRevisionId,
            'effectiveAt' => $this->instant($mapping->effectiveAt),
        ];
        if (! hash_equals($this->digest($body), $mapping->mappingRevisionId)) {
            throw new InvalidArgumentException('mapping.mappingRevisionId does not match its deterministic identity.');
        }

        $fingerprint = $this->digest([
            'source' => $source,
            'scope' => $scope,
            'disposition' => $mapping->disposition->value,
            'effectiveAt' => $this->instant($mapping->effectiveAt),
        ]);
        if (! hash_equals($fingerprint, $mapping->contentFingerprint)) {
            throw new InvalidArgumentException('mapping.contentFingerprint does not match its deterministic content.');
        }
    }

    /**
     * @param  list<FinancialSourceMappingRevisionDTO>  $existingRevisions
     */
    public function planMappingAppend(
        FinancialSourceMappingRevisionDTO $incoming,
        array $existingRevisions,
    ): FinancialMappingAppendPlanDTO {
        $this->validateMapping($incoming);
        foreach ($existingRevisions as $existing) {
            if (! $existing instanceof FinancialSourceMappingRevisionDTO) {
                throw new InvalidArgumentException('existingRevisions must contain mapping DTOs.');
            }
            $this->validateMapping($existing);
        }

        $sameKey = array_values(array_filter(
            $existingRevisions,
            static fn (FinancialSourceMappingRevisionDTO $item): bool => $item->mappingKey === $incoming->mappingKey,
        ));

        foreach ($sameKey as $item) {
            if ($item->mappingRevisionId === $incoming->mappingRevisionId) {
                return new FinancialMappingAppendPlanDTO(
                    FinancialAppendClassification::UNCHANGED_REPLAY,
                    false,
                    false,
                );
            }
            if ($item->revisionNumber === $incoming->revisionNumber) {
                return new FinancialMappingAppendPlanDTO(
                    FinancialAppendClassification::CONTENT_CONFLICT,
                    false,
                    false,
                    'different_content_for_existing_mapping_revision',
                );
            }
        }

        if ($sameKey === []) {
            $accepted = $incoming->revisionNumber === 1
                && $incoming->supersedesMappingRevisionId === null;

            return new FinancialMappingAppendPlanDTO(
                $accepted
                    ? FinancialAppendClassification::NEW_MAPPING
                    : FinancialAppendClassification::REVISION_GAP,
                $accepted,
                $accepted && $incoming->disposition === FinancialMappingDisposition::ACTIVE,
            );
        }

        usort(
            $sameKey,
            static fn (FinancialSourceMappingRevisionDTO $left, FinancialSourceMappingRevisionDTO $right): int => $right->revisionNumber <=> $left->revisionNumber,
        );
        $latest = $sameKey[0];
        $expectedRevision = $latest->revisionNumber + 1;
        $accepted = $incoming->revisionNumber === $expectedRevision
            && $incoming->supersedesMappingRevisionId === $latest->mappingRevisionId;

        return new FinancialMappingAppendPlanDTO(
            $accepted
                ? FinancialAppendClassification::NEW_REVISION
                : FinancialAppendClassification::REVISION_GAP,
            $accepted,
            $accepted && $incoming->disposition === FinancialMappingDisposition::ACTIVE,
            null,
            $expectedRevision,
            $latest->mappingRevisionId,
        );
    }

    public function validateBatch(FinancialSnapshotBatchDTO $batch): void
    {
        $snapshot = $batch->snapshot;
        $this->validateSnapshot($snapshot);

        if (count($batch->records) !== $snapshot->recordCount) {
            throw new InvalidArgumentException('batch record count does not match snapshot.recordCount.');
        }

        $ordinals = [];
        foreach ($batch->records as $index => $record) {
            if (! $record instanceof FinancialSnapshotRecordDTO) {
                throw new InvalidArgumentException('batch.records must contain record DTOs.');
            }
            $this->validateRecord($record, $snapshot, $index);
            if (array_key_exists($record->recordOrdinal, $ordinals)) {
                throw new InvalidArgumentException('batch contains duplicate record ordinals.');
            }
            $ordinals[$record->recordOrdinal] = true;
        }
        $actualOrdinals = array_keys($ordinals);
        sort($actualOrdinals, SORT_NUMERIC);
        $expectedOrdinals = $batch->records === []
            ? []
            : range(0, count($batch->records) - 1);
        if ($actualOrdinals !== $expectedOrdinals) {
            throw new InvalidArgumentException('batch record ordinals must be contiguous from zero.');
        }

        if ($snapshot->snapshotKind === FinancialSnapshotKind::PLANNED_POSITION) {
            if ($batch->records !== [] || $batch->planRevision === null) {
                throw new InvalidArgumentException('planned-position batches require one plan and no records.');
            }
            $this->validatePlan($batch->planRevision, $snapshot);
        } elseif ($batch->planRevision !== null) {
            throw new InvalidArgumentException('non-plan snapshots cannot contain a plan revision.');
        }

        $this->validateReceipt($batch->receipt, $snapshot);
    }

    /**
     * @param  list<FinancialSnapshotDTO>  $existingSnapshots
     * @param  list<string>  $existingSourceReceiptIds
     */
    public function planSnapshotAppend(
        FinancialSnapshotBatchDTO $batch,
        array $existingSnapshots = [],
        array $existingSourceReceiptIds = [],
    ): FinancialSnapshotAppendPlanDTO {
        $this->validateBatch($batch);
        foreach ($existingSnapshots as $snapshot) {
            if (! $snapshot instanceof FinancialSnapshotDTO) {
                throw new InvalidArgumentException('existingSnapshots must contain snapshot DTOs.');
            }
            $this->validateSnapshot($snapshot);
        }
        foreach ($existingSourceReceiptIds as $receiptId) {
            $this->assertDigest($receiptId, 'existingSourceReceiptIds[]');
        }

        $incoming = $batch->snapshot;
        $exact = array_filter(
            $existingSnapshots,
            static fn (FinancialSnapshotDTO $item): bool => $item->snapshotId === $incoming->snapshotId,
        );
        if ($exact !== []) {
            $classification = in_array($batch->receipt->sourceReceiptId, $existingSourceReceiptIds, true)
                ? FinancialAppendClassification::UNCHANGED_REPLAY
                : FinancialAppendClassification::RECEIPT_ONLY;
        } elseif ($this->snapshotMatches(
            $existingSnapshots,
            static fn (FinancialSnapshotDTO $item): bool => $item->sourceVersionKey === $incoming->sourceVersionKey,
        )) {
            $classification = FinancialAppendClassification::CONTENT_CONFLICT;
        } else {
            $streamSnapshots = array_values(array_filter(
                $existingSnapshots,
                static fn (FinancialSnapshotDTO $item): bool => $item->streamKey === $incoming->streamKey,
            ));
            $newerExists = $this->snapshotMatches(
                $streamSnapshots,
                fn (FinancialSnapshotDTO $item): bool => $this->instant($item->sourceAsOfAt) > $this->instant($incoming->sourceAsOfAt),
            );
            $classification = $newerExists
                ? FinancialAppendClassification::STALE_SNAPSHOT
                : ($streamSnapshots !== []
                    ? FinancialAppendClassification::NEW_REVISION
                    : FinancialAppendClassification::NEW_SNAPSHOT);
        }

        $appendSnapshot = ! in_array($classification, [
            FinancialAppendClassification::UNCHANGED_REPLAY,
            FinancialAppendClassification::RECEIPT_ONLY,
        ], true);
        $appendReceipt = $classification !== FinancialAppendClassification::UNCHANGED_REPLAY;
        $promotionEligible = in_array($classification, [
            FinancialAppendClassification::NEW_SNAPSHOT,
            FinancialAppendClassification::NEW_REVISION,
            FinancialAppendClassification::RECEIPT_ONLY,
        ], true)
            && $incoming->status === FinancialReconciliationStatus::PASS
            && $incoming->sourcePublishable
            && $incoming->policyPublishable
            && $batch->receipt->status === FinancialReconciliationStatus::PASS
            && $batch->receipt->freshness->value === 'current';

        $receipt = $appendReceipt
            ? $this->persistedReceipt($batch, $classification, $promotionEligible)
            : null;

        return new FinancialSnapshotAppendPlanDTO(
            $classification,
            $appendSnapshot,
            $appendSnapshot,
            $appendSnapshot && $batch->planRevision !== null,
            $appendReceipt,
            $promotionEligible,
            $batch,
            $receipt,
        );
    }

    public function scopeKey(
        int $accountId,
        int $organizerId,
        int $eventId,
        string $universityId,
        string $cycleId,
    ): string {
        return $this->digest([
            'accountId' => $accountId,
            'organizerId' => $organizerId,
            'eventId' => $eventId,
            'universityId' => trim($universityId),
            'cycleId' => trim($cycleId),
        ]);
    }

    public function digest(array $value): string
    {
        try {
            return hash('sha256', json_encode(
                $this->canonicalize($value),
                JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE,
            ));
        } catch (JsonException $exception) {
            throw new InvalidArgumentException('Financial identity contains non-JSON data.', 0, $exception);
        }
    }

    public function instant(DateTimeInterface $value): string
    {
        return DateTimeImmutable::createFromInterface($value)
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('Y-m-d\TH:i:s.v\Z');
    }

    private function validateSnapshot(FinancialSnapshotDTO $snapshot): void
    {
        $this->assertPositiveInteger($snapshot->financialScopeId, 'snapshot.financialScopeId');
        $this->assertDigest($snapshot->scopeKey, 'snapshot.scopeKey');
        $this->assertNonEmpty($snapshot->universityId, 'snapshot.universityId');
        $this->assertNonEmpty($snapshot->cycleId, 'snapshot.cycleId');
        $this->assertNonEmpty($snapshot->sourceNamespace, 'snapshot.sourceNamespace');
        $this->assertNonEmpty($snapshot->adapterVersion, 'snapshot.adapterVersion');
        $this->assertDigest($snapshot->snapshotId, 'snapshot.snapshotId');
        $this->assertDigest($snapshot->streamKey, 'snapshot.streamKey');
        $this->assertDigest($snapshot->sourceVersionKey, 'snapshot.sourceVersionKey');
        $this->assertDigest($snapshot->contentFingerprint, 'snapshot.contentFingerprint');
        $this->assertNonNegativeInteger($snapshot->recordCount, 'snapshot.recordCount');
        if ($snapshot->policyVersion !== null) {
            $this->assertNonEmpty($snapshot->policyVersion, 'snapshot.policyVersion');
        }
        if ($snapshot->policyPublishable && ! $snapshot->sourcePublishable) {
            throw new InvalidArgumentException('snapshot.policyPublishable requires sourcePublishable.');
        }
        if ($this->instant($snapshot->importedAt) < $this->instant($snapshot->sourceAsOfAt)) {
            throw new InvalidArgumentException('snapshot.importedAt cannot precede sourceAsOfAt.');
        }

        $source = $this->snapshotSource($snapshot);
        $scope = $this->scopeIdentity(
            $snapshot->scopeKey,
            $snapshot->universityId,
            $snapshot->cycleId,
        );
        $expectedStreamKey = $this->digest([
            'snapshotKind' => $snapshot->snapshotKind->value,
            'source' => $source,
            'scope' => $scope,
        ]);
        $expectedSourceVersionKey = $this->digest([
            'streamKey' => $expectedStreamKey,
            'sourceAsOfAt' => $this->instant($snapshot->sourceAsOfAt),
        ]);
        $expectedSnapshotId = $this->digest([
            'sourceVersionKey' => $expectedSourceVersionKey,
            'contentFingerprint' => $snapshot->contentFingerprint,
        ]);
        if (! hash_equals($expectedStreamKey, $snapshot->streamKey)
            || ! hash_equals($expectedSourceVersionKey, $snapshot->sourceVersionKey)
            || ! hash_equals($expectedSnapshotId, $snapshot->snapshotId)) {
            throw new InvalidArgumentException('snapshot deterministic identity does not match its content.');
        }

        $this->assertMetricMap(
            $snapshot->summary,
            self::SUMMARY_KEYS[$snapshot->snapshotKind->value],
            'snapshot.summary',
        );
    }

    private function validateRecord(
        FinancialSnapshotRecordDTO $record,
        FinancialSnapshotDTO $snapshot,
        int $index,
    ): void {
        $field = "batch.records[$index]";
        $this->assertDigest($record->snapshotRecordId, "$field.snapshotRecordId");
        $this->assertDigest($record->snapshotId, "$field.snapshotId");
        $this->assertDigest($record->mappingRevisionId, "$field.mappingRevisionId");
        $this->assertDigest($record->sourceIdentityKey, "$field.sourceIdentityKey");
        $this->assertDigest($record->contentFingerprint, "$field.contentFingerprint");
        $this->assertNonNegativeInteger($record->recordOrdinal, "$field.recordOrdinal");
        $this->assertNonEmpty($record->providerStatus, "$field.providerStatus");
        $this->assertNonEmpty($record->financialStatus, "$field.financialStatus");
        $this->assertCurrency($record->currency, "$field.currency");
        $this->assertPositiveInteger($record->quantity, "$field.quantity");
        if ($record->snapshotId !== $snapshot->snapshotId) {
            throw new InvalidArgumentException("$field belongs to another snapshot.");
        }

        foreach ([
            'grossCents', 'processorFeeCents', 'processorFeeRefundCents',
            'platformFeeCents', 'platformFeeRefundCents', 'refundCents',
            'paymentReversalCents', 'disputeFeeCents',
        ] as $moneyField) {
            $value = $record->{$moneyField};
            if ($value !== null) {
                $this->assertNonNegativeInteger($value, "$field.$moneyField");
            }
        }
        foreach (['providerNetCents', 'netSettlementCents'] as $moneyField) {
            $value = $record->{$moneyField};
            if ($value !== null) {
                $this->assertSafeInteger($value, "$field.$moneyField");
            }
        }
        $this->assertRefundBound($record->refundCents, $record->grossCents, "$field.refundCents");
        $this->assertRefundBound(
            $record->processorFeeRefundCents,
            $record->processorFeeCents,
            "$field.processorFeeRefundCents",
        );
        $this->assertRefundBound(
            $record->platformFeeRefundCents,
            $record->platformFeeCents,
            "$field.platformFeeRefundCents",
        );
        foreach (['processorFeeProvenance', 'platformFeeProvenance'] as $provenanceField) {
            $value = $record->{$provenanceField};
            if ($value !== null && ! in_array($value, ['actual', 'estimated'], true)) {
                throw new InvalidArgumentException("$field.$provenanceField is unsupported.");
            }
        }
        if ($this->instant($record->sourceUpdatedAt) < $this->instant($record->sourceOccurredAt)) {
            throw new InvalidArgumentException("$field sourceUpdatedAt precedes sourceOccurredAt.");
        }
        if ($this->instant($record->sourceUpdatedAt) > $this->instant($snapshot->sourceAsOfAt)) {
            throw new InvalidArgumentException("$field follows snapshot sourceAsOfAt.");
        }

        $expectedRecordId = $this->digest([
            'snapshotId' => $snapshot->snapshotId,
            'recordOrdinal' => $record->recordOrdinal,
            'sourceIdentityKey' => $record->sourceIdentityKey,
        ]);
        if (! hash_equals($expectedRecordId, $record->snapshotRecordId)) {
            throw new InvalidArgumentException("$field deterministic identity does not match its content.");
        }
    }

    private function validatePlan(FinancialPlanRevisionDTO $plan, FinancialSnapshotDTO $snapshot): void
    {
        $this->assertDigest($plan->planRevisionId, 'plan.planRevisionId');
        $this->assertDigest($plan->snapshotId, 'plan.snapshotId');
        $this->assertDigest($plan->mappingRevisionId, 'plan.mappingRevisionId');
        $this->assertDigest($plan->sourceIdentityKey, 'plan.sourceIdentityKey');
        $this->assertDigest($plan->contentFingerprint, 'plan.contentFingerprint');
        if ($plan->snapshotId !== $snapshot->snapshotId
            || $this->instant($plan->asOfAt) !== $this->instant($snapshot->sourceAsOfAt)) {
            throw new InvalidArgumentException('plan does not belong to the snapshot source version.');
        }
        if ($plan->pricingConvention !== 'customer_price_less_commission'
            || $plan->basisPointRounding !== 'half_up_to_cent') {
            throw new InvalidArgumentException('plan pricing convention or rounding is unsupported.');
        }

        foreach ([
            'ticketCustomerPriceCents', 'perTicketCommissionCents', 'fundraisingGoalCents',
            'plannedTicketCustomerChargeCents', 'plannedCommissionCents',
            'plannedTicketProceedsCents', 'plannedUniversityFundraisingAllocationCents',
            'plannedDonorboxFeeCents', 'plannedGrossIncomeCents',
            'plannedIncomeAfterDonorboxFeeCents',
        ] as $field) {
            $this->assertNonNegativeInteger($plan->{$field}, "plan.$field");
        }
        $this->assertPositiveInteger($plan->ticketQuantity, 'plan.ticketQuantity');
        foreach (['universityAllocationBasisPoints', 'donorboxFeeBasisPoints'] as $field) {
            $this->assertNonNegativeInteger($plan->{$field}, "plan.$field");
            if ($plan->{$field} > 10_000) {
                throw new InvalidArgumentException("plan.$field must be at most 10000.");
            }
        }

        $body = [
            'snapshotId' => $plan->snapshotId,
            'mappingRevisionId' => $plan->mappingRevisionId,
            'sourceIdentityKey' => $plan->sourceIdentityKey,
            'contentFingerprint' => $plan->contentFingerprint,
            'asOfAt' => $this->instant($plan->asOfAt),
            'pricingConvention' => $plan->pricingConvention,
            'basisPointRounding' => $plan->basisPointRounding,
            'ticketCustomerPriceCents' => $plan->ticketCustomerPriceCents,
            'ticketQuantity' => $plan->ticketQuantity,
            'perTicketCommissionCents' => $plan->perTicketCommissionCents,
            'fundraisingGoalCents' => $plan->fundraisingGoalCents,
            'universityAllocationBasisPoints' => $plan->universityAllocationBasisPoints,
            'donorboxFeeBasisPoints' => $plan->donorboxFeeBasisPoints,
            'plannedTicketCustomerChargeCents' => $plan->plannedTicketCustomerChargeCents,
            'plannedCommissionCents' => $plan->plannedCommissionCents,
            'plannedTicketProceedsCents' => $plan->plannedTicketProceedsCents,
            'plannedUniversityFundraisingAllocationCents' => $plan->plannedUniversityFundraisingAllocationCents,
            'plannedDonorboxFeeCents' => $plan->plannedDonorboxFeeCents,
            'plannedGrossIncomeCents' => $plan->plannedGrossIncomeCents,
            'plannedIncomeAfterDonorboxFeeCents' => $plan->plannedIncomeAfterDonorboxFeeCents,
        ];
        if (! hash_equals($this->digest($body), $plan->planRevisionId)) {
            throw new InvalidArgumentException('plan.planRevisionId does not match its deterministic content.');
        }
    }

    private function validateReceipt(
        FinancialReconciliationReceiptDTO $receipt,
        FinancialSnapshotDTO $snapshot,
    ): void {
        $this->assertDigest($receipt->sourceReceiptId, 'receipt.sourceReceiptId');
        $this->assertDigest($receipt->snapshotId, 'receipt.snapshotId');
        if ($receipt->snapshotId !== $snapshot->snapshotId
            || $this->instant($receipt->sourceAsOfAt) !== $this->instant($snapshot->sourceAsOfAt)
            || $this->instant($receipt->generatedAt) !== $this->instant($snapshot->importedAt)) {
            throw new InvalidArgumentException('receipt does not belong to the snapshot import.');
        }
        if ($receipt->status !== $snapshot->status
            || $receipt->sourcePublishable !== $snapshot->sourcePublishable
            || $receipt->policyPublishable !== $snapshot->policyPublishable) {
            throw new InvalidArgumentException('receipt status or publication flags do not match the snapshot.');
        }
        if ($receipt->policyPublishable && ! $receipt->sourcePublishable) {
            throw new InvalidArgumentException('receipt.policyPublishable requires sourcePublishable.');
        }
        foreach ([
            'sourceRecordCount', 'importedRecordCount', 'excludedCount',
            'conflictCount', 'discrepancyCount',
        ] as $field) {
            $this->assertNonNegativeInteger($receipt->{$field}, "receipt.$field");
        }
        $expectedImportedCount = $snapshot->snapshotKind === FinancialSnapshotKind::PLANNED_POSITION
            ? 1
            : $snapshot->recordCount;
        if ($receipt->importedRecordCount !== $expectedImportedCount
            || $receipt->discrepancyCount !== count($receipt->discrepancies)) {
            throw new InvalidArgumentException('receipt counts do not match projected content.');
        }
        $this->assertReceiptMetricMap($receipt->sourceTotals, 'receipt.sourceTotals');
        $this->assertReceiptMetricMap($receipt->importedTotals, 'receipt.importedTotals');

        foreach ($receipt->discrepancies as $index => $item) {
            if (! is_array($item)) {
                throw new InvalidArgumentException("receipt.discrepancies[$index] must be an object-shaped array.");
            }
            $keys = array_keys($item);
            sort($keys, SORT_STRING);
            $expectedKeys = ['delta', 'field', 'importedValue', 'sourceValue'];
            if ($keys !== $expectedKeys) {
                throw new InvalidArgumentException("receipt.discrepancies[$index] has unsupported fields.");
            }
            if (! in_array($item['field'], self::RECEIPT_METRIC_KEYS, true)) {
                throw new InvalidArgumentException("receipt.discrepancies[$index].field is unsupported or private.");
            }
            foreach (['sourceValue', 'importedValue', 'delta'] as $field) {
                $this->assertSafeInteger($item[$field], "receipt.discrepancies[$index].$field");
            }
        }
    }

    private function persistedReceipt(
        FinancialSnapshotBatchDTO $batch,
        FinancialAppendClassification $classification,
        bool $promotionEligible,
    ): FinancialPersistedReceiptDTO {
        $recordedAt = $batch->snapshot->importedAt;
        $persistenceReceiptId = $this->digest([
            'snapshotId' => $batch->snapshot->snapshotId,
            'sourceReceiptId' => $batch->receipt->sourceReceiptId,
            'appendClassification' => $classification->value,
            'promotionEligible' => $promotionEligible,
            'recordedAt' => $this->instant($recordedAt),
        ]);

        return new FinancialPersistedReceiptDTO(
            $persistenceReceiptId,
            $batch->receipt->sourceReceiptId,
            $batch->snapshot->snapshotId,
            $classification,
            $batch->receipt->status,
            $batch->receipt->freshness,
            $batch->receipt->sourcePublishable,
            $batch->receipt->policyPublishable,
            $promotionEligible,
            $batch->receipt->sourceRecordCount,
            $batch->receipt->importedRecordCount,
            $batch->receipt->excludedCount,
            $batch->receipt->conflictCount,
            $batch->receipt->discrepancyCount,
            $batch->receipt->sourceTotals,
            $batch->receipt->importedTotals,
            $batch->receipt->discrepancies,
            $batch->receipt->sourceAsOfAt,
            $batch->receipt->generatedAt,
            $recordedAt,
        );
    }

    private function mappingSource(FinancialSourceMappingRevisionDTO $mapping): array
    {
        return [
            'system' => $mapping->sourceSystem->value,
            'namespace' => trim($mapping->sourceNamespace),
            'objectKind' => $mapping->sourceObjectKind->value,
            'objectId' => trim($mapping->sourceObjectId),
        ];
    }

    private function snapshotSource(FinancialSnapshotDTO $snapshot): array
    {
        return [
            'system' => $snapshot->sourceSystem->value,
            'namespace' => trim($snapshot->sourceNamespace),
            'adapterVersion' => trim($snapshot->adapterVersion),
        ];
    }

    private function scopeIdentity(string $scopeKey, string $universityId, string $cycleId): array
    {
        return [
            'scopeKey' => $scopeKey,
            'universityId' => trim($universityId),
            'cycleId' => trim($cycleId),
        ];
    }

    private function assertReceiptMetricMap(array $value, string $fieldName): void
    {
        $this->assertMetricMap($value, self::RECEIPT_METRIC_KEYS, $fieldName);
        foreach ($value as $key => $item) {
            if ($item === null) {
                if (in_array($key, [
                    'contentFingerprint',
                    'currency',
                    'provenance',
                    'includedProviderStatuses',
                ], true)) {
                    throw new InvalidArgumentException("$fieldName.$key cannot be null.");
                }

                continue;
            }
            if ($key === 'contentFingerprint') {
                if (! is_string($item)) {
                    throw new InvalidArgumentException("$fieldName.$key must be a digest string.");
                }
                $this->assertDigest($item, "$fieldName.$key");

                continue;
            }
            if ($key === 'currency') {
                if (! is_string($item)) {
                    throw new InvalidArgumentException("$fieldName.$key must be a currency string.");
                }
                $this->assertCurrency($item, "$fieldName.$key");

                continue;
            }
            if ($key === 'provenance') {
                if (! is_string($item)
                    || ! in_array($item, self::RECEIPT_PROVENANCE_VALUES, true)) {
                    throw new InvalidArgumentException(
                        "$fieldName.$key must be an allowlisted control provenance.",
                    );
                }

                continue;
            }
            if ($key === 'includedProviderStatuses') {
                if (! is_array($item) || ! array_is_list($item)) {
                    throw new InvalidArgumentException("$fieldName.$key must be a list of provider statuses.");
                }
                foreach ($item as $index => $status) {
                    if (! is_string($status)
                        || ! in_array($status, self::RECEIPT_PROVIDER_STATUS_VALUES, true)) {
                        throw new InvalidArgumentException(
                            "$fieldName.$key[$index] must be an allowlisted provider status.",
                        );
                    }
                }

                continue;
            }
            if (! is_int($item)) {
                throw new InvalidArgumentException("$fieldName.$key must be an integer or null.");
            }
            $this->assertSafeInteger($item, "$fieldName.$key");
        }
    }

    private function assertMetricMap(array $value, array $allowedKeys, string $fieldName): void
    {
        foreach ($value as $key => $item) {
            if (! is_string($key) || ! in_array($key, $allowedKeys, true)) {
                throw new InvalidArgumentException("$fieldName contains unsupported or private keys.");
            }
            if ($item === null || is_bool($item) || is_string($item)) {
                continue;
            }
            if (is_int($item)) {
                $this->assertSafeInteger($item, "$fieldName.$key");

                continue;
            }
            if (is_array($item) && array_is_list($item)) {
                $allStrings = true;
                foreach ($item as $entry) {
                    if (! is_string($entry)) {
                        $allStrings = false;
                        break;
                    }
                }
                if ($allStrings) {
                    continue;
                }
            }
            throw new InvalidArgumentException("$fieldName.$key contains a noncanonical metric value.");
        }
    }

    /**
     * @param  list<FinancialSnapshotDTO>  $snapshots
     */
    private function snapshotMatches(array $snapshots, callable $predicate): bool
    {
        foreach ($snapshots as $snapshot) {
            if ($predicate($snapshot)) {
                return true;
            }
        }

        return false;
    }

    private function assertRefundBound(?int $refund, ?int $gross, string $fieldName): void
    {
        if ($refund !== null && $gross !== null && $refund > $gross) {
            throw new InvalidArgumentException("$fieldName cannot exceed its gross amount.");
        }
    }

    private function assertDigest(?string $value, string $fieldName): void
    {
        if ($value === null || preg_match('/^[0-9a-f]{64}$/', $value) !== 1) {
            throw new InvalidArgumentException("$fieldName must be a lowercase SHA-256 digest.");
        }
    }

    private function assertCurrency(string $value, string $fieldName): void
    {
        if (preg_match('/^[A-Z]{3}$/', $value) !== 1) {
            throw new InvalidArgumentException("$fieldName must be uppercase ISO-4217.");
        }
    }

    private function assertNonEmpty(string $value, string $fieldName): void
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException("$fieldName must be a non-empty string.");
        }
    }

    private function assertPositiveInteger(int $value, string $fieldName): void
    {
        $this->assertSafeInteger($value, $fieldName);
        if ($value < 1) {
            throw new InvalidArgumentException("$fieldName must be positive.");
        }
    }

    private function assertNonNegativeInteger(int $value, string $fieldName): void
    {
        $this->assertSafeInteger($value, $fieldName);
        if ($value < 0) {
            throw new InvalidArgumentException("$fieldName must be non-negative.");
        }
    }

    private function assertSafeInteger(int $value, string $fieldName): void
    {
        if ($value < -self::MAX_SAFE_INTEGER || $value > self::MAX_SAFE_INTEGER) {
            throw new InvalidArgumentException("$fieldName exceeds the cross-runtime safe integer range.");
        }
    }

    private function canonicalize(mixed $value): mixed
    {
        if (! is_array($value)) {
            return $value;
        }
        if (array_is_list($value)) {
            return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
        }
        ksort($value, SORT_STRING);

        return array_map(fn (mixed $item): mixed => $this->canonicalize($item), $value);
    }
}
