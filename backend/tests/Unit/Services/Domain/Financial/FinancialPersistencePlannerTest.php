<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Financial;

use DateTimeImmutable;
use HiEvents\DomainObjects\Enums\FinancialAppendClassification;
use HiEvents\DomainObjects\Enums\FinancialSnapshotKind;
use HiEvents\DomainObjects\Enums\FinancialSourceObjectKind;
use HiEvents\DomainObjects\Enums\FinancialSourceSystem;
use HiEvents\DomainObjects\Status\FinancialFreshness;
use HiEvents\DomainObjects\Status\FinancialMappingDisposition;
use HiEvents\DomainObjects\Status\FinancialReconciliationStatus;
use HiEvents\Services\Domain\Financial\DTOs\FinancialPlanRevisionDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialReconciliationReceiptDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialScopeDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSnapshotBatchDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSnapshotDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSourceMappingRevisionDTO;
use HiEvents\Services\Domain\Financial\FinancialPersistencePlanner;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class FinancialPersistencePlannerTest extends TestCase
{
    private FinancialPersistencePlanner $planner;

    protected function setUp(): void
    {
        parent::setUp();
        $this->planner = new FinancialPersistencePlanner;
    }

    public function test_scope_identity_is_exact_and_deterministic(): void
    {
        $scope = $this->scope();
        $this->planner->validateScope($scope);

        $differentEventKey = $this->planner->digest([
            'accountId' => $scope->accountId,
            'organizerId' => $scope->organizerId,
            'eventId' => $scope->eventId + 1,
            'universityId' => $scope->universityId,
            'cycleId' => $scope->cycleId,
        ]);

        self::assertNotSame($scope->scopeKey, $differentEventKey);
    }

    public function test_mapping_plans_new_revision_replay_conflict_and_gap(): void
    {
        $scope = $this->scope();
        $first = $this->mapping($scope);
        $new = $this->planner->planMappingAppend($first, []);
        self::assertSame(FinancialAppendClassification::NEW_MAPPING, $new->classification);
        self::assertTrue($new->append);
        self::assertTrue($new->promotable);

        $replay = $this->planner->planMappingAppend($first, [$first]);
        self::assertSame(FinancialAppendClassification::UNCHANGED_REPLAY, $replay->classification);
        self::assertFalse($replay->append);

        $conflict = $this->mapping($scope, effectiveAt: '2026-07-25T01:00:00Z');
        $conflictPlan = $this->planner->planMappingAppend($conflict, [$first]);
        self::assertSame(FinancialAppendClassification::CONTENT_CONFLICT, $conflictPlan->classification);
        self::assertFalse($conflictPlan->append);

        $gap = $this->mapping($scope, revision: 3, supersedes: $first->mappingRevisionId);
        $gapPlan = $this->planner->planMappingAppend($gap, [$first]);
        self::assertSame(FinancialAppendClassification::REVISION_GAP, $gapPlan->classification);
        self::assertSame(2, $gapPlan->expectedRevisionNumber);

        $second = $this->mapping(
            $scope,
            revision: 2,
            supersedes: $first->mappingRevisionId,
            effectiveAt: '2026-07-25T02:00:00Z',
        );
        $revisionPlan = $this->planner->planMappingAppend($second, [$first]);
        self::assertSame(FinancialAppendClassification::NEW_REVISION, $revisionPlan->classification);
        self::assertTrue($revisionPlan->append);
    }

    public function test_snapshot_plans_all_replay_and_freshness_classifications(): void
    {
        $scope = $this->scope();
        $mapping = $this->mapping($scope);
        $first = $this->planBatch($scope, $mapping);

        $new = $this->planner->planSnapshotAppend($first);
        self::assertSame(FinancialAppendClassification::NEW_SNAPSHOT, $new->classification);
        self::assertTrue($new->appendSnapshot);
        self::assertTrue($new->appendReceipt);
        self::assertTrue($new->promotionEligible);
        self::assertNotNull($new->receipt);

        $replay = $this->planner->planSnapshotAppend(
            $first,
            [$first->snapshot],
            [$first->receipt->sourceReceiptId],
        );
        self::assertSame(FinancialAppendClassification::UNCHANGED_REPLAY, $replay->classification);
        self::assertFalse($replay->appendSnapshot);
        self::assertFalse($replay->appendReceipt);

        $newReceipt = $this->planBatch(
            $scope,
            $mapping,
            sourceReceiptSeed: 'receipt-2',
        );
        $receiptOnly = $this->planner->planSnapshotAppend($newReceipt, [$first->snapshot], []);
        self::assertSame(FinancialAppendClassification::RECEIPT_ONLY, $receiptOnly->classification);
        self::assertFalse($receiptOnly->appendSnapshot);
        self::assertTrue($receiptOnly->appendReceipt);
        self::assertTrue($receiptOnly->promotionEligible);

        $conflicting = $this->planBatch($scope, $mapping, contentSeed: 'changed');
        $conflict = $this->planner->planSnapshotAppend($conflicting, [$first->snapshot], []);
        self::assertSame(FinancialAppendClassification::CONTENT_CONFLICT, $conflict->classification);
        self::assertTrue($conflict->appendSnapshot);
        self::assertFalse($conflict->promotionEligible);

        $newer = $this->planBatch(
            $scope,
            $mapping,
            sourceAsOfAt: '2026-07-26T00:00:00Z',
            importedAt: '2026-07-26T00:01:00Z',
            contentSeed: 'newer',
        );
        $stale = $this->planner->planSnapshotAppend($first, [$newer->snapshot], []);
        self::assertSame(FinancialAppendClassification::STALE_SNAPSHOT, $stale->classification);
        self::assertTrue($stale->appendSnapshot);
        self::assertFalse($stale->promotionEligible);

        $revision = $this->planner->planSnapshotAppend($newer, [$first->snapshot], []);
        self::assertSame(FinancialAppendClassification::NEW_REVISION, $revision->classification);
        self::assertTrue($revision->promotionEligible);
    }

    public function test_receipt_metric_tokens_reject_identity_like_values(): void
    {
        $scope = $this->scope();
        $mapping = $this->mapping($scope);
        $batch = $this->planBatch($scope, $mapping);
        $unsafeReceipt = new FinancialReconciliationReceiptDTO(
            $batch->receipt->sourceReceiptId,
            $batch->receipt->snapshotId,
            $batch->receipt->status,
            $batch->receipt->freshness,
            $batch->receipt->sourcePublishable,
            $batch->receipt->policyPublishable,
            $batch->receipt->sourceRecordCount,
            $batch->receipt->importedRecordCount,
            $batch->receipt->excludedCount,
            $batch->receipt->conflictCount,
            $batch->receipt->discrepancyCount,
            [...$batch->receipt->sourceTotals, 'provenance' => 'person@example.invalid'],
            $batch->receipt->importedTotals,
            $batch->receipt->discrepancies,
            $batch->receipt->sourceAsOfAt,
            $batch->receipt->generatedAt,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('allowlisted');
        $this->planner->validateBatch(new FinancialSnapshotBatchDTO(
            $batch->snapshot,
            $batch->records,
            $batch->planRevision,
            $unsafeReceipt,
        ));
    }

    public function test_string_bearing_receipt_metrics_cannot_be_null(): void
    {
        $scope = $this->scope();
        $mapping = $this->mapping($scope);
        $batch = $this->planBatch($scope, $mapping);
        $unsafeReceipt = new FinancialReconciliationReceiptDTO(
            $batch->receipt->sourceReceiptId,
            $batch->receipt->snapshotId,
            $batch->receipt->status,
            $batch->receipt->freshness,
            $batch->receipt->sourcePublishable,
            $batch->receipt->policyPublishable,
            $batch->receipt->sourceRecordCount,
            $batch->receipt->importedRecordCount,
            $batch->receipt->excludedCount,
            $batch->receipt->conflictCount,
            $batch->receipt->discrepancyCount,
            [...$batch->receipt->sourceTotals, 'provenance' => null],
            $batch->receipt->importedTotals,
            $batch->receipt->discrepancies,
            $batch->receipt->sourceAsOfAt,
            $batch->receipt->generatedAt,
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('cannot be null');
        $this->planner->validateBatch(new FinancialSnapshotBatchDTO(
            $batch->snapshot,
            $batch->records,
            $batch->planRevision,
            $unsafeReceipt,
        ));
    }

    public function test_privacy_allowlists_fail_before_persistence(): void
    {
        $scope = $this->scope();
        $mapping = $this->mapping($scope);
        $batch = $this->planBatch($scope, $mapping);
        $unsafeSnapshot = new FinancialSnapshotDTO(
            $batch->snapshot->snapshotId,
            $batch->snapshot->streamKey,
            $batch->snapshot->sourceVersionKey,
            $batch->snapshot->financialScopeId,
            $batch->snapshot->scopeKey,
            $batch->snapshot->universityId,
            $batch->snapshot->cycleId,
            $batch->snapshot->snapshotKind,
            $batch->snapshot->sourceSystem,
            $batch->snapshot->sourceNamespace,
            $batch->snapshot->adapterVersion,
            $batch->snapshot->sourceAsOfAt,
            $batch->snapshot->importedAt,
            $batch->snapshot->policyVersion,
            $batch->snapshot->contentFingerprint,
            $batch->snapshot->status,
            $batch->snapshot->sourcePublishable,
            $batch->snapshot->policyPublishable,
            $batch->snapshot->recordCount,
            [...$batch->snapshot->summary, 'donorEmail' => 'private@example.invalid'],
        );

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('unsupported or private');
        $this->planner->validateBatch(new FinancialSnapshotBatchDTO(
            $unsafeSnapshot,
            $batch->records,
            $batch->planRevision,
            $batch->receipt,
        ));
    }

    private function scope(): FinancialScopeDTO
    {
        $scopeKey = $this->planner->digest([
            'accountId' => 11,
            'organizerId' => 12,
            'eventId' => 13,
            'universityId' => 'gcu',
            'cycleId' => '2026-fall',
        ]);

        return new FinancialScopeDTO(
            $scopeKey,
            11,
            12,
            13,
            'gcu',
            '2026-fall',
            'America/Phoenix',
            'USD',
            new DateTimeImmutable('2026-07-25T00:00:00Z'),
        );
    }

    private function mapping(
        FinancialScopeDTO $scope,
        int $revision = 1,
        ?string $supersedes = null,
        string $effectiveAt = '2026-07-25T00:00:00Z',
    ): FinancialSourceMappingRevisionDTO {
        $source = [
            'system' => 'google_sheet',
            'namespace' => 'gcu_budget_2026',
            'objectKind' => 'plan_record',
            'objectId' => 'gcu-budget-2026',
        ];
        $scopeIdentity = [
            'scopeKey' => $scope->scopeKey,
            'universityId' => $scope->universityId,
            'cycleId' => $scope->cycleId,
        ];
        $mappingKey = $this->planner->digest($source);
        $effective = new DateTimeImmutable($effectiveAt);
        $body = [
            'mappingKey' => $mappingKey,
            'source' => $source,
            'scope' => $scopeIdentity,
            'revisionNumber' => $revision,
            'disposition' => 'active',
            'supersedesMappingRevisionId' => $supersedes,
            'effectiveAt' => $this->planner->instant($effective),
        ];
        $fingerprint = $this->planner->digest([
            'source' => $source,
            'scope' => $scopeIdentity,
            'disposition' => 'active',
            'effectiveAt' => $this->planner->instant($effective),
        ]);

        return new FinancialSourceMappingRevisionDTO(
            $this->planner->digest($body),
            $mappingKey,
            99,
            $scope->scopeKey,
            $scope->universityId,
            $scope->cycleId,
            $revision,
            FinancialSourceSystem::GOOGLE_SHEET,
            'gcu_budget_2026',
            FinancialSourceObjectKind::PLAN_RECORD,
            'gcu-budget-2026',
            FinancialMappingDisposition::ACTIVE,
            $supersedes,
            $fingerprint,
            $effective,
            new DateTimeImmutable('2026-07-26T00:00:00Z'),
        );
    }

    private function planBatch(
        FinancialScopeDTO $scope,
        FinancialSourceMappingRevisionDTO $mapping,
        string $sourceAsOfAt = '2026-07-25T00:00:00Z',
        string $importedAt = '2026-07-25T00:01:00Z',
        string $contentSeed = 'plan-v1',
        string $sourceReceiptSeed = 'receipt-1',
    ): FinancialSnapshotBatchDTO {
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
            99,
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
            $this->planner->digest(['receipt' => $sourceReceiptSeed]),
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
}
