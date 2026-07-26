<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Financial;

use DateTimeImmutable;
use HiEvents\DomainObjects\Enums\FinancialAppendClassification;
use HiEvents\DomainObjects\Enums\FinancialSnapshotKind;
use HiEvents\DomainObjects\Enums\FinancialSourceSystem;
use HiEvents\DomainObjects\Status\FinancialFreshness;
use HiEvents\DomainObjects\Status\FinancialReconciliationStatus;
use HiEvents\Exceptions\FinancialReadModelValidationException;
use HiEvents\Services\Domain\Financial\DTOs\FinancialPersistedReceiptDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialPersistedSnapshotDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialPlanRevisionDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSnapshotDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSnapshotRecordDTO;
use HiEvents\Services\Domain\Financial\FinancialLaunchPolicy;
use HiEvents\Services\Domain\Financial\FinancialReadModelService;
use Tests\TestCase;

class FinancialReadModelServiceTest extends TestCase
{
    private FinancialReadModelService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FinancialReadModelService;
    }

    public function test_source_controlled_evidence_remains_visible_while_policy_recognition_is_withheld(): void
    {
        $result = $this->compose(
            ticketPacket: $this->ticketPacket(),
            settlementPacket: $this->settlementPacket(
                semanticStatus: 'application_fee_already_deducted',
                fullyPromotable: false,
            ),
            donationPacket: $this->donationPacket(),
            includeReconciliation: true,
        );

        $this->assertTrue($result->sourceEvidence['tickets']['fullyPromotable']);
        $this->assertTrue($result->sourceEvidence['settlement']['sourceControlled']);
        $this->assertFalse($result->sourceEvidence['settlement']['fullyPromotable']);
        $this->assertTrue($result->sourceEvidence['donations']['sourceControlled']);
        $this->assertFalse($result->sourceEvidence['donations']['fullyPromotable']);
        $this->assertSame('application_fee_already_deducted', $result->tickets['policyValidationStatus']);
        $this->assertNull($result->tickets['recognizedRevenueCents']);
        $this->assertSame(1_990_000, $result->donations['allocationBaseCents']);
        $this->assertNull($result->donations['recognizedRevenueCents']);
        $this->assertSame(0, $result->currentPosition['knownCents']);
        $this->assertSame(['tickets', 'donations'], $result->currentPosition['missingOrUnpublishableSources']);
        $this->assertFalse($result->publishable);
        $this->assertNotNull($result->reconciliation);
        $this->assertSame(
            796_000,
            $result->reconciliation['policyValidation']['fundraisingRecognition']['candidateRevenueCents'],
        );
    }

    public function test_fully_promotable_policy_compatible_ticket_contributes_without_publishing_fundraising(): void
    {
        $result = $this->compose(
            ticketPacket: $this->ticketPacket(),
            settlementPacket: $this->settlementPacket(
                semanticStatus: 'fixed_deduction_still_required',
                fullyPromotable: true,
            ),
            donationPacket: $this->donationPacket(),
            includeReconciliation: false,
        );

        $this->assertTrue($result->sourceEvidence['settlement']['fullyPromotable']);
        $this->assertTrue($result->tickets['policyPublishable']);
        $this->assertSame(4_300, $result->tickets['recognizedRevenueCents']);
        $this->assertSame(4_300, $result->currentPosition['knownCents']);
        $this->assertSame([['source' => 'tickets', 'cents' => 4_300]], $result->currentPosition['components']);
        $this->assertSame(['donations'], $result->currentPosition['missingOrUnpublishableSources']);
        $this->assertFalse($result->publishable);
        $this->assertNull($result->reconciliation);
    }

    public function test_source_controlled_policy_compatible_settlement_can_publish_without_full_promotion(): void
    {
        $result = $this->compose(
            ticketPacket: $this->ticketPacket(),
            settlementPacket: $this->settlementPacket(
                semanticStatus: 'fixed_deduction_still_required',
                fullyPromotable: false,
                policyPublishable: true,
            ),
            donationPacket: null,
            includeReconciliation: false,
        );

        $this->assertTrue($result->sourceEvidence['settlement']['sourceControlled']);
        $this->assertFalse($result->sourceEvidence['settlement']['fullyPromotable']);
        $this->assertTrue($result->sourceEvidence['settlement']['policyPublishable']);
        $this->assertTrue($result->tickets['policyPublishable']);
        $this->assertSame(4_300, $result->tickets['recognizedRevenueCents']);
    }

    public function test_confirmed_fundraising_policy_can_publish_controlled_gross_without_net_promotion(): void
    {
        $result = $this->compose(
            ticketPacket: null,
            settlementPacket: null,
            donationPacket: $this->donationPacket(),
            includeReconciliation: false,
            policy: $this->confirmedFundraisingPolicy(),
        );

        $this->assertTrue($result->sourceEvidence['donations']['sourceControlled']);
        $this->assertFalse($result->sourceEvidence['donations']['fullyPromotable']);
        $this->assertTrue($result->donations['policyPublishable']);
        $this->assertSame(796_000, $result->donations['recognizedRevenueCents']);
        $this->assertSame(796_000, $result->currentPosition['knownCents']);
        $this->assertSame([['source' => 'donations', 'cents' => 796_000]], $result->currentPosition['components']);
        $this->assertSame(['tickets'], $result->currentPosition['missingOrUnpublishableSources']);
    }

    public function test_missing_source_packets_remain_explicitly_missing_and_withheld(): void
    {
        $result = $this->compose(
            ticketPacket: null,
            settlementPacket: null,
            donationPacket: null,
            includeReconciliation: false,
        );

        $this->assertSame('missing', $result->tickets['status']);
        $this->assertSame('missing', $result->tickets['settlement']['status']);
        $this->assertSame('missing', $result->donations['status']);
        $this->assertFalse($result->sourceEvidence['tickets']['available']);
        $this->assertFalse($result->sourceEvidence['settlement']['available']);
        $this->assertFalse($result->sourceEvidence['donations']['available']);
        $this->assertSame(['tickets', 'donations'], $result->currentPosition['missingOrUnpublishableSources']);
        $this->assertNull($result->reconciliation);
    }

    public function test_stale_or_conflicting_packet_cannot_cross_source_controlled_boundary(): void
    {
        $this->expectException(FinancialReadModelValidationException::class);
        $this->expectExceptionMessage('stale, conflicting, or source-withheld');

        $this->compose(
            ticketPacket: $this->ticketPacket(),
            settlementPacket: $this->settlementPacket(
                semanticStatus: 'application_fee_already_deducted',
                fullyPromotable: false,
                freshness: FinancialFreshness::STALE,
            ),
            donationPacket: null,
            includeReconciliation: false,
        );
    }

    public function test_conflict_classification_cannot_cross_source_controlled_boundary(): void
    {
        $this->expectException(FinancialReadModelValidationException::class);
        $this->expectExceptionMessage('stale, conflicting, or source-withheld');

        $this->compose(
            ticketPacket: $this->ticketPacket(),
            settlementPacket: $this->settlementPacket(
                semanticStatus: 'application_fee_already_deducted',
                fullyPromotable: false,
                appendClassification: FinancialAppendClassification::CONTENT_CONFLICT,
            ),
            donationPacket: null,
            includeReconciliation: false,
        );
    }

    public function test_stale_append_classification_cannot_cross_source_controlled_boundary(): void
    {
        $this->expectException(FinancialReadModelValidationException::class);
        $this->expectExceptionMessage('stale, conflicting, or source-withheld');

        $this->compose(
            ticketPacket: $this->ticketPacket(),
            settlementPacket: $this->settlementPacket(
                semanticStatus: 'application_fee_already_deducted',
                fullyPromotable: false,
                appendClassification: FinancialAppendClassification::STALE_SNAPSHOT,
            ),
            donationPacket: null,
            includeReconciliation: false,
        );
    }

    public function test_unchanged_replay_cannot_appear_as_a_persisted_source_receipt(): void
    {
        $this->expectException(FinancialReadModelValidationException::class);
        $this->expectExceptionMessage('stale, conflicting, or source-withheld');

        $this->compose(
            ticketPacket: $this->ticketPacket(),
            settlementPacket: $this->settlementPacket(
                semanticStatus: 'application_fee_already_deducted',
                fullyPromotable: false,
                appendClassification: FinancialAppendClassification::UNCHANGED_REPLAY,
            ),
            donationPacket: null,
            includeReconciliation: false,
        );
    }

    public function test_exact_scope_mismatch_fails_before_composition(): void
    {
        $packet = $this->ticketPacket(universityId: 'asu');

        $this->expectException(FinancialReadModelValidationException::class);
        $this->expectExceptionMessage('outside the requested exact scope');

        $this->compose(
            ticketPacket: $packet,
            settlementPacket: null,
            donationPacket: null,
            includeReconciliation: false,
        );
    }

    public function test_persisted_settlement_semantics_must_match_money_evidence(): void
    {
        $this->expectException(FinancialReadModelValidationException::class);
        $this->expectExceptionMessage('semantics no longer match money totals');

        $this->compose(
            ticketPacket: $this->ticketPacket(),
            settlementPacket: $this->settlementPacket(
                semanticStatus: 'unresolved',
                fullyPromotable: false,
            ),
            donationPacket: null,
            includeReconciliation: false,
        );
    }

    private function compose(
        ?FinancialPersistedSnapshotDTO $ticketPacket,
        ?FinancialPersistedSnapshotDTO $settlementPacket,
        ?FinancialPersistedSnapshotDTO $donationPacket,
        bool $includeReconciliation,
        ?FinancialLaunchPolicy $policy = null,
    ) {
        return $this->service->compose(
            scopeKey: $this->scopeKey(),
            accountId: 7,
            organizerId: 19,
            eventId: 31,
            universityId: 'gcu',
            cycleId: '2026-fall',
            planPacket: $this->planPacket(),
            ticketPacket: $ticketPacket,
            settlementPacket: $settlementPacket,
            donationPacket: $donationPacket,
            cutoffAt: new DateTimeImmutable('2026-07-25T23:59:59-07:00'),
            generatedAt: new DateTimeImmutable('2026-07-26T00:05:00-07:00'),
            includeReconciliation: $includeReconciliation,
            policy: $policy,
        );
    }

    private function confirmedFundraisingPolicy(): FinancialLaunchPolicy
    {
        $current = FinancialLaunchPolicy::current();

        return new FinancialLaunchPolicy(
            policyVersion: $current->policyVersion,
            effectiveAt: $current->effectiveAt,
            reportingTimezone: $current->reportingTimezone,
            sourceFreshnessSeconds: $current->sourceFreshnessSeconds,
            ticketRevenueBasis: $current->ticketRevenueBasis,
            fixedDeductionCents: $current->fixedDeductionCents,
            eligibleTransactionDefinition: $current->eligibleTransactionDefinition,
            fundraisingAllocationBasisPoints: $current->fundraisingAllocationBasisPoints,
            fundraisingProcessingFeesReduceRevenue: $current->fundraisingProcessingFeesReduceRevenue,
            fundraisingProcessingFeeRationale: $current->fundraisingProcessingFeeRationale,
            fundraisingProcessingFeeConfirmation: 'confirmed',
        );
    }

    private function planPacket(): FinancialPersistedSnapshotDTO
    {
        $snapshot = $this->snapshot(
            kind: FinancialSnapshotKind::PLANNED_POSITION,
            source: FinancialSourceSystem::GOOGLE_SHEET,
            namespace: 'gcu_budget_2026',
            recordCount: 0,
            summary: [
                'plannedTicketProceedsCents' => 7_350_000,
                'plannedFundraisingGoalCents' => 2_000_000,
                'plannedGrossIncomeCents' => 15_350_000,
            ],
            fullyPromotable: true,
        );
        $plan = new FinancialPlanRevisionDTO(
            planRevisionId: str_repeat('2', 64),
            snapshotId: $snapshot->snapshotId,
            mappingRevisionId: str_repeat('3', 64),
            sourceIdentityKey: str_repeat('4', 64),
            contentFingerprint: $snapshot->contentFingerprint,
            asOfAt: new DateTimeImmutable('2026-07-25T00:00:00Z'),
            pricingConvention: 'customer_price_less_commission',
            basisPointRounding: 'half_up_to_cent',
            ticketCustomerPriceCents: 5_500,
            ticketQuantity: 1_500,
            perTicketCommissionCents: 600,
            fundraisingGoalCents: 2_000_000,
            universityAllocationBasisPoints: 4_000,
            donorboxFeeBasisPoints: 175,
            plannedTicketCustomerChargeCents: 8_250_000,
            plannedCommissionCents: 900_000,
            plannedTicketProceedsCents: 7_350_000,
            plannedUniversityFundraisingAllocationCents: 800_000,
            plannedDonorboxFeeCents: 14_000,
            plannedGrossIncomeCents: 15_350_000,
            plannedIncomeAfterDonorboxFeeCents: 15_210_000,
        );

        return new FinancialPersistedSnapshotDTO(
            $snapshot,
            [],
            $plan,
            $this->receipt($snapshot, true, FinancialFreshness::CURRENT, [
                'contentFingerprint' => $snapshot->contentFingerprint,
            ]),
        );
    }

    private function ticketPacket(string $universityId = 'gcu'): FinancialPersistedSnapshotDTO
    {
        $snapshot = $this->snapshot(
            kind: FinancialSnapshotKind::SPARK_TICKET,
            source: FinancialSourceSystem::SPARK,
            namespace: 'spark_gcu_2026',
            recordCount: 1,
            summary: [
                'eligibleTransactionCount' => 1,
                'eligibilityDefinition' => FinancialLaunchPolicy::SPARK_ELIGIBLE_TRANSACTION_DEFINITION,
                'eligibilitySourceGrain' => 'spark_attendee_row',
                'zeroPriceReviewCount' => 0,
                'unpaidOrUnsettledCount' => 0,
            ],
            fullyPromotable: true,
            universityId: $universityId,
        );
        $record = $this->record(
            snapshotId: $snapshot->snapshotId,
            providerStatus: 'paid',
            financialStatus: 'paid',
            grossCents: 5_500,
            processorFeeCents: 190,
            platformFeeCents: 600,
            refundCents: 0,
            paymentReversalCents: 0,
            providerNetCents: null,
            netSettlementCents: 4_710,
            settlementSemanticStatus: null,
            platformFeeProvenance: 'estimated',
            processorFeeProvenance: 'estimated',
        );

        return new FinancialPersistedSnapshotDTO(
            $snapshot,
            [$record],
            null,
            $this->receipt($snapshot, true, FinancialFreshness::CURRENT, [
                'recordCount' => 1,
                'customerChargeCents' => 5_500,
            ]),
        );
    }

    private function settlementPacket(
        string $semanticStatus,
        bool $fullyPromotable,
        FinancialFreshness $freshness = FinancialFreshness::CURRENT,
        FinancialAppendClassification $appendClassification = FinancialAppendClassification::NEW_SNAPSHOT,
        ?bool $policyPublishable = null,
    ): FinancialPersistedSnapshotDTO {
        $customerChargeCents = $semanticStatus === 'fixed_deduction_still_required' ? 5_095 : 5_695;
        $snapshot = $this->snapshot(
            kind: FinancialSnapshotKind::STRIPE_SETTLEMENT,
            source: FinancialSourceSystem::STRIPE,
            namespace: 'stripe_gcu_2026',
            recordCount: 1,
            summary: [
                'semanticStatus' => $semanticStatus,
                'policyCompatible' => $semanticStatus === 'fixed_deduction_still_required',
                'connectedNetCents' => 4_900,
                'connectedSettlementAfterAdjustmentsCents' => 4_900,
            ],
            fullyPromotable: $fullyPromotable,
            policyPublishable: $policyPublishable,
        );
        $record = $this->record(
            snapshotId: $snapshot->snapshotId,
            providerStatus: 'succeeded',
            financialStatus: 'paid',
            grossCents: $customerChargeCents,
            processorFeeCents: 195,
            platformFeeCents: 600,
            refundCents: 0,
            paymentReversalCents: 0,
            providerNetCents: 4_900,
            netSettlementCents: 4_900,
            settlementSemanticStatus: $semanticStatus,
            platformFeeProvenance: 'actual',
            processorFeeProvenance: 'actual',
        );
        $totals = [
            'recordCount' => 1,
            'customerChargeCents' => $customerChargeCents,
            'stripeProcessingFeeCents' => 195,
            'applicationFeeCents' => 600,
            'connectedNetCents' => 4_900,
            'refundCents' => 0,
            'applicationFeeRefundCents' => 0,
            'disputeAmountCents' => 0,
            'disputeFeeCents' => 0,
            'connectedSettlementAfterAdjustmentsCents' => 4_900,
        ];

        return new FinancialPersistedSnapshotDTO(
            $snapshot,
            [$record],
            null,
            $this->receipt(
                $snapshot,
                $fullyPromotable,
                $freshness,
                $totals,
                policyPublishable: $policyPublishable,
                appendClassification: $appendClassification,
            ),
        );
    }

    private function donationPacket(): FinancialPersistedSnapshotDTO
    {
        $snapshot = $this->snapshot(
            kind: FinancialSnapshotKind::DONORBOX,
            source: FinancialSourceSystem::DONORBOX,
            namespace: 'donorbox_gcu_2026',
            recordCount: 1,
            summary: [
                'controlledRecordCount' => 1,
                'controlledGrossCents' => 2_000_000,
                'incompleteRecordCount' => 1,
                'contractStatus' => 'provisional',
                'grossControlStatus' => 'verified',
                'netControlStatus' => 'unavailable',
                'sourceWindowFromAt' => '2026-07-01T00:00:00Z',
                'sourceTimeZone' => 'America/Phoenix',
            ],
            fullyPromotable: false,
        );
        $record = $this->record(
            snapshotId: $snapshot->snapshotId,
            providerStatus: 'Paid',
            financialStatus: 'recognized_candidate',
            grossCents: 2_000_000,
            processorFeeCents: null,
            platformFeeCents: null,
            refundCents: 10_000,
            paymentReversalCents: null,
            providerNetCents: null,
            netSettlementCents: null,
            settlementSemanticStatus: null,
            platformFeeProvenance: null,
            processorFeeProvenance: null,
            recognitionDisposition: 'recognized_candidate',
        );

        return new FinancialPersistedSnapshotDTO(
            $snapshot,
            [$record],
            null,
            $this->receipt(
                $snapshot,
                false,
                FinancialFreshness::CURRENT,
                [
                    'recordCount' => 1,
                    'grossCents' => 2_000_000,
                    'amountRefundedCents' => 10_000,
                ],
                ['includedProviderStatuses' => ['Paid', 'Refunded']],
                FinancialReconciliationStatus::REVIEW_REQUIRED,
            ),
        );
    }

    /** @param array<string, bool|int|string|null|list<string>> $summary */
    private function snapshot(
        FinancialSnapshotKind $kind,
        FinancialSourceSystem $source,
        string $namespace,
        int $recordCount,
        array $summary,
        bool $fullyPromotable,
        string $universityId = 'gcu',
        ?bool $policyPublishable = null,
    ): FinancialSnapshotDTO {
        $snapshotId = hash('sha256', $kind->value.$namespace.$universityId);

        return new FinancialSnapshotDTO(
            snapshotId: $snapshotId,
            streamKey: hash('sha256', 'stream'.$snapshotId),
            sourceVersionKey: hash('sha256', 'version'.$snapshotId),
            financialScopeId: 1,
            scopeKey: $this->scopeKey(),
            universityId: $universityId,
            cycleId: '2026-fall',
            snapshotKind: $kind,
            sourceSystem: $source,
            sourceNamespace: $namespace,
            adapterVersion: '2026-07-25.1',
            sourceAsOfAt: new DateTimeImmutable('2026-07-25T00:00:00Z'),
            importedAt: new DateTimeImmutable('2026-07-25T00:01:00Z'),
            policyVersion: '2026-07-25.2',
            contentFingerprint: hash('sha256', 'content'.$snapshotId),
            status: $fullyPromotable
                ? FinancialReconciliationStatus::PASS
                : FinancialReconciliationStatus::REVIEW_REQUIRED,
            sourcePublishable: true,
            policyPublishable: $policyPublishable ?? $fullyPromotable,
            recordCount: $recordCount,
            summary: $summary,
        );
    }

    /**
     * @param  array<string, bool|int|string|null|list<string>>  $importedTotals
     * @param  array<string, bool|int|string|null|list<string>>  $sourceTotals
     */
    private function receipt(
        FinancialSnapshotDTO $snapshot,
        bool $fullyPromotable,
        FinancialFreshness $freshness,
        array $importedTotals,
        array $sourceTotals = [],
        ?FinancialReconciliationStatus $status = null,
        ?bool $policyPublishable = null,
        FinancialAppendClassification $appendClassification = FinancialAppendClassification::NEW_SNAPSHOT,
    ): FinancialPersistedReceiptDTO {
        $status ??= $fullyPromotable
            ? FinancialReconciliationStatus::PASS
            : FinancialReconciliationStatus::REVIEW_REQUIRED;
        $sourceTotals = [...$importedTotals, ...$sourceTotals];

        return new FinancialPersistedReceiptDTO(
            persistenceReceiptId: hash('sha256', 'persistence'.$snapshot->snapshotId),
            sourceReceiptId: hash('sha256', 'source'.$snapshot->snapshotId),
            snapshotId: $snapshot->snapshotId,
            appendClassification: $appendClassification,
            status: $status,
            freshness: $freshness,
            sourcePublishable: true,
            policyPublishable: $policyPublishable ?? $fullyPromotable,
            promotionEligible: $fullyPromotable,
            sourceRecordCount: max(1, $snapshot->recordCount),
            importedRecordCount: max(1, $snapshot->recordCount),
            excludedCount: 0,
            conflictCount: 0,
            discrepancyCount: 0,
            sourceTotals: $sourceTotals,
            importedTotals: $importedTotals,
            discrepancies: [],
            sourceAsOfAt: $snapshot->sourceAsOfAt,
            generatedAt: new DateTimeImmutable('2026-07-25T00:02:00Z'),
            recordedAt: new DateTimeImmutable('2026-07-25T00:03:00Z'),
        );
    }

    private function record(
        string $snapshotId,
        string $providerStatus,
        string $financialStatus,
        ?int $grossCents,
        ?int $processorFeeCents,
        ?int $platformFeeCents,
        ?int $refundCents,
        ?int $paymentReversalCents,
        ?int $providerNetCents,
        ?int $netSettlementCents,
        ?string $settlementSemanticStatus,
        ?string $platformFeeProvenance,
        ?string $processorFeeProvenance,
        ?string $recognitionDisposition = null,
    ): FinancialSnapshotRecordDTO {
        return new FinancialSnapshotRecordDTO(
            snapshotRecordId: hash('sha256', 'record'.$snapshotId),
            snapshotId: $snapshotId,
            recordOrdinal: 0,
            mappingRevisionId: str_repeat('3', 64),
            sourceIdentityKey: hash('sha256', 'identity'.$snapshotId),
            contentFingerprint: hash('sha256', 'record-content'.$snapshotId),
            providerStatus: $providerStatus,
            financialStatus: $financialStatus,
            recognitionDisposition: $recognitionDisposition,
            sourceCompletenessStatus: null,
            sourceMethod: null,
            currency: 'USD',
            quantity: 1,
            grossCents: $grossCents,
            processorFeeCents: $processorFeeCents,
            processorFeeRefundCents: 0,
            processorFeeProvenance: $processorFeeProvenance,
            platformFeeCents: $platformFeeCents,
            platformFeeRefundCents: 0,
            platformFeeProvenance: $platformFeeProvenance,
            refundCents: $refundCents,
            paymentReversalCents: $paymentReversalCents,
            disputeFeeCents: 0,
            providerNetCents: $providerNetCents,
            netSettlementCents: $netSettlementCents,
            settlementSemanticStatus: $settlementSemanticStatus,
            sourceOccurredAt: new DateTimeImmutable('2026-07-24T23:00:00Z'),
            sourceUpdatedAt: new DateTimeImmutable('2026-07-24T23:30:00Z'),
        );
    }

    private function scopeKey(): string
    {
        return str_repeat('a', 64);
    }
}
