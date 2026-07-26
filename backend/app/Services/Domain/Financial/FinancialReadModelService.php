<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Financial;

use DateTimeImmutable;
use DateTimeInterface;
use HiEvents\DomainObjects\Enums\FinancialAppendClassification;
use HiEvents\DomainObjects\Enums\FinancialSnapshotKind;
use HiEvents\DomainObjects\Status\FinancialFreshness;
use HiEvents\DomainObjects\Status\FinancialReconciliationStatus;
use HiEvents\Exceptions\FinancialReadModelValidationException;
use HiEvents\Services\Domain\Financial\DTOs\FinancialPersistedSnapshotDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialReadModelDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialSnapshotRecordDTO;

class FinancialReadModelService
{
    private const MAX_SAFE_INTEGER = 9_007_199_254_740_991;

    public function compose(
        string $scopeKey,
        int $accountId,
        int $organizerId,
        int $eventId,
        string $universityId,
        string $cycleId,
        FinancialPersistedSnapshotDTO $planPacket,
        ?FinancialPersistedSnapshotDTO $ticketPacket,
        ?FinancialPersistedSnapshotDTO $settlementPacket,
        ?FinancialPersistedSnapshotDTO $donationPacket,
        DateTimeInterface $cutoffAt,
        DateTimeInterface $generatedAt,
        bool $includeReconciliation,
        ?FinancialLaunchPolicy $policy = null,
    ): FinancialReadModelDTO {
        $policy ??= FinancialLaunchPolicy::current();
        $cutoff = DateTimeImmutable::createFromInterface($cutoffAt);
        $generated = DateTimeImmutable::createFromInterface($generatedAt);
        if ($generated < $cutoff) {
            throw new FinancialReadModelValidationException('generatedAt cannot precede cutoffAt.');
        }

        $scope = [
            'scopeKey' => $scopeKey,
            'accountId' => $accountId,
            'organizerId' => $organizerId,
            'eventId' => $eventId,
            'universityId' => trim($universityId),
            'cycleId' => trim($cycleId),
        ];
        $this->assertScope($scope);

        $planEvidence = $this->packetEvidence(
            $planPacket,
            FinancialSnapshotKind::PLANNED_POSITION,
            $scope,
            $cutoff,
            'latest_promotable',
            $policy->policyVersion,
        );
        $ticketEvidence = $ticketPacket === null
            ? $this->missingEvidence('latest_promotable')
            : $this->packetEvidence(
                $ticketPacket,
                FinancialSnapshotKind::SPARK_TICKET,
                $scope,
                $cutoff,
                'latest_promotable',
                $policy->policyVersion,
            );
        $settlementEvidence = $settlementPacket === null
            ? $this->missingEvidence('latest_source_controlled')
            : $this->packetEvidence(
                $settlementPacket,
                FinancialSnapshotKind::STRIPE_SETTLEMENT,
                $scope,
                $cutoff,
                'latest_source_controlled',
                $policy->policyVersion,
            );
        $donationEvidence = $donationPacket === null
            ? $this->missingEvidence('latest_source_controlled')
            : $this->packetEvidence(
                $donationPacket,
                FinancialSnapshotKind::DONORBOX,
                $scope,
                $cutoff,
                'latest_source_controlled',
                $policy->policyVersion,
            );

        $plan = $this->plan($planPacket, $cutoff);
        $ticket = $ticketPacket === null
            ? $this->missingTicket()
            : $this->ticket($ticketPacket, $policy, $cutoff);
        $settlement = $settlementPacket === null
            ? $this->missingSettlement()
            : $this->settlement($settlementPacket, $policy, $cutoff, $settlementEvidence);
        $donation = $donationPacket === null
            ? $this->missingDonation()
            : $this->donation($donationPacket, $policy, $cutoff, $donationEvidence);

        $eligibleTransactionCount = $ticket['eligibility']['eligibleTransactionCount'] ?? null;
        $eligibilityDefinitionMatches = $eligibleTransactionCount !== null
            && $ticket['eligibility']['definition'] === $policy->eligibleTransactionDefinition;
        $settlementCountMatches = $eligibleTransactionCount !== null
            && $settlement['actuals'] !== null
            && $eligibleTransactionCount === $settlement['actuals']['recordCount'];
        $ticketPolicyPublishable = $ticket['sourcePublishable']
            && $settlement['sourcePublishable']
            && $settlement['policyPublishable']
            && $eligibilityDefinitionMatches
            && $settlementCountMatches;
        $recognizedTicketRevenue = $ticketPolicyPublishable
            ? $policy->ticketRevenue(
                $settlement['actuals']['connectedNetCents'],
                $eligibleTransactionCount,
                $settlement['actuals']['immediateAdjustmentCents'],
            )
            : null;

        $fundraisingCandidate = $donation['grossActuals'] === null
            ? null
            : $policy->fundraisingRevenue(
                $donation['grossActuals']['grossCents'],
                $donation['grossActuals']['amountRefundedCents'],
                $donation['observedProcessingFeeCents'],
            );
        $fundraisingPolicyPublishable = $donation['sourcePublishable']
            && $fundraisingCandidate !== null
            && $fundraisingCandidate['validationRequired'] === false;

        $ticketQuantityVariance = $ticket['actuals'] === null
            ? null
            : $this->checkedSubtract(
                $ticket['actuals']['quantity'],
                $plan['ticketQuantity'],
                'ticketQuantityVariance',
            );
        $ticketProceedsVarianceCents = $recognizedTicketRevenue === null
            ? null
            : $this->checkedSubtract(
                $recognizedTicketRevenue['recognizedRevenueCents'],
                $plan['totals']['plannedTicketProceedsCents'],
                'ticketProceedsVarianceCents',
            );
        $fundraisingGrossVarianceCents = $donation['grossActuals'] === null
            ? null
            : $this->checkedSubtract(
                $donation['grossActuals']['grossCents'],
                $plan['totals']['plannedFundraisingGoalCents'],
                'fundraisingGrossVarianceCents',
            );

        $positionComponents = [];
        if ($ticketPolicyPublishable && $recognizedTicketRevenue !== null) {
            $positionComponents[] = [
                'source' => 'tickets',
                'cents' => $recognizedTicketRevenue['recognizedRevenueCents'],
            ];
        }
        if ($fundraisingPolicyPublishable && $fundraisingCandidate !== null) {
            $positionComponents[] = [
                'source' => 'donations',
                'cents' => $fundraisingCandidate['recognizedRevenueCents'],
            ];
        }
        $knownCents = 0;
        foreach ($positionComponents as $component) {
            $knownCents = $this->checkedAdd($knownCents, $component['cents'], 'knownCents');
        }
        $missingOrUnpublishableSources = [];
        if (! $ticketPolicyPublishable) {
            $missingOrUnpublishableSources[] = 'tickets';
        }
        if (! $fundraisingPolicyPublishable) {
            $missingOrUnpublishableSources[] = 'donations';
        }
        $publishable = $missingOrUnpublishableSources === [];

        $tickets = [
            'status' => $ticket['status'],
            'sourcePublishable' => $ticket['sourcePublishable'],
            'policyPublishable' => $ticketPolicyPublishable,
            'policyValidationStatus' => $settlement['semanticStatus'],
            'eligibility' => $ticket['eligibility'],
            'eligibilityDefinitionMatches' => $eligibilityDefinitionMatches,
            'settlementCountMatches' => $settlementCountMatches,
            'recognizedRevenueCents' => $recognizedTicketRevenue['recognizedRevenueCents'] ?? null,
            'actuals' => $ticket['actuals'],
            'sourceStatus' => $ticket['sourceStatus'],
            'settlement' => [
                'status' => $settlement['status'],
                'sourcePublishable' => $settlement['sourcePublishable'],
                'policyPublishable' => $settlement['policyPublishable'],
                'actuals' => $settlement['actuals'],
                'sourceStatus' => $settlement['sourceStatus'],
            ],
        ];
        $donations = [
            'status' => $donation['status'],
            'sourcePublishable' => $donation['sourcePublishable'],
            'fullyPromotable' => $donation['fullyPromotable'],
            'policyPublishable' => $fundraisingPolicyPublishable,
            'recognizedRevenueCents' => $fundraisingPolicyPublishable
                ? $fundraisingCandidate['recognizedRevenueCents']
                : null,
            'allocationBaseCents' => $fundraisingCandidate['allocationBaseCents'] ?? null,
            'validationRequired' => $fundraisingCandidate['validationRequired'] ?? true,
            'grossActuals' => $donation['grossActuals'],
            'netActuals' => $donation['netActuals'],
            'sourceStatus' => $donation['sourceStatus'],
        ];
        $reconciliation = $includeReconciliation ? [
            'ticketReceipt' => $ticketPacket?->receipt,
            'ticketSettlementReceipt' => $settlementPacket?->receipt,
            'donationReceipt' => $donationPacket?->receipt,
            'ticketObservedActuals' => $ticket['diagnosticActuals'],
            'ticketSettlementObservedActuals' => $settlement['actuals'],
            'donationObservedActuals' => $donation['diagnosticActuals'],
            'policyValidation' => [
                'ticketNetSemantics' => $settlement['semantics'],
                'fundraisingProcessingFeeConfirmation' => $policy->fundraisingProcessingFeeConfirmation,
                'fundraisingRecognition' => $fundraisingCandidate === null ? null : [
                    'allocationBaseCents' => $fundraisingCandidate['allocationBaseCents'],
                    'candidateRevenueCents' => $fundraisingCandidate['recognizedRevenueCents'],
                    'validationRequired' => $fundraisingCandidate['validationRequired'],
                    'policyPublishable' => $fundraisingPolicyPublishable,
                ],
            ],
        ] : null;

        return new FinancialReadModelDTO(
            scope: $scope,
            cutoffAt: $cutoff,
            generatedAt: $generated,
            reportingTimezone: $policy->reportingTimezone,
            financialPolicy: $this->policyArray($policy),
            plan: $plan,
            tickets: $tickets,
            donations: $donations,
            variances: [
                'ticketQuantity' => $ticketQuantityVariance,
                'ticketProceedsCents' => $ticketProceedsVarianceCents,
                'fundraisingGrossCents' => $fundraisingGrossVarianceCents,
            ],
            currentPosition: [
                'knownCents' => $knownCents,
                'components' => $positionComponents,
                'complete' => $publishable,
                'missingOrUnpublishableSources' => $missingOrUnpublishableSources,
            ],
            sourceEvidence: [
                'plan' => $planEvidence,
                'tickets' => $ticketEvidence,
                'settlement' => $settlementEvidence,
                'donations' => $donationEvidence,
            ],
            publishable: $publishable,
            reconciliation: $reconciliation,
        );
    }

    /** @param array<string, int|string> $scope */
    private function packetEvidence(
        FinancialPersistedSnapshotDTO $packet,
        FinancialSnapshotKind $kind,
        array $scope,
        DateTimeImmutable $cutoff,
        string $selection,
        string $policyVersion,
    ): array {
        $snapshot = $packet->snapshot;
        $receipt = $packet->receipt;
        if ($receipt === null) {
            throw new FinancialReadModelValidationException("{$kind->value} packet is missing its receipt.");
        }
        if ($snapshot->snapshotKind !== $kind) {
            throw new FinancialReadModelValidationException("{$kind->value} packet has the wrong snapshot kind.");
        }
        $this->assertPacketScope($packet, $scope);
        $this->assertNotAfterCutoff($snapshot->sourceAsOfAt, $cutoff, "{$kind->value}.sourceAsOfAt");
        if ($receipt->sourceAsOfAt->getTimestamp() !== $snapshot->sourceAsOfAt->getTimestamp()) {
            throw new FinancialReadModelValidationException("{$kind->value} receipt source time does not match snapshot.");
        }
        if ($snapshot->recordCount !== count($packet->records)) {
            throw new FinancialReadModelValidationException("{$kind->value} record count does not match persisted records.");
        }
        foreach ($packet->records as $index => $record) {
            $this->assertNotAfterCutoff(
                $record->sourceOccurredAt,
                $cutoff,
                "{$kind->value}.records[$index].sourceOccurredAt",
            );
            $this->assertNotAfterCutoff(
                $record->sourceUpdatedAt,
                $cutoff,
                "{$kind->value}.records[$index].sourceUpdatedAt",
            );
        }

        $sourceControlledClassification = in_array(
            $receipt->appendClassification,
            [
                FinancialAppendClassification::NEW_SNAPSHOT,
                FinancialAppendClassification::NEW_REVISION,
                FinancialAppendClassification::RECEIPT_ONLY,
            ],
            true,
        );
        $sourceControlled = $sourceControlledClassification
            && $snapshot->sourcePublishable
            && $receipt->sourcePublishable
            && $receipt->freshness === FinancialFreshness::CURRENT
            && in_array(
                $snapshot->status,
                [FinancialReconciliationStatus::PASS, FinancialReconciliationStatus::REVIEW_REQUIRED],
                true,
            )
            && in_array(
                $receipt->status,
                [FinancialReconciliationStatus::PASS, FinancialReconciliationStatus::REVIEW_REQUIRED],
                true,
            );
        $policyVersionMatches = $snapshot->policyVersion === $policyVersion;
        $fullyPromotable = $sourceControlled
            && $policyVersionMatches
            && $snapshot->status === FinancialReconciliationStatus::PASS
            && $receipt->status === FinancialReconciliationStatus::PASS
            && $snapshot->policyPublishable
            && $receipt->policyPublishable
            && $receipt->promotionEligible;

        if (! $sourceControlled) {
            throw new FinancialReadModelValidationException("{$kind->value} packet is stale, conflicting, or source-withheld.");
        }
        if ($selection === 'latest_promotable' && ! $fullyPromotable) {
            throw new FinancialReadModelValidationException("{$kind->value} packet is not fully promotable.");
        }

        return [
            'available' => true,
            'selection' => $selection,
            'sourceControlled' => $sourceControlled,
            'fullyPromotable' => $fullyPromotable,
            'policyPublishable' => $policyVersionMatches
                && $snapshot->policyPublishable
                && $receipt->policyPublishable,
            'policyVersionMatches' => $policyVersionMatches,
            'status' => $receipt->status->value,
            'freshness' => $receipt->freshness->value,
            'snapshotId' => $snapshot->snapshotId,
            'sourceAsOfAt' => $snapshot->sourceAsOfAt->format(DATE_ATOM),
        ];
    }

    private function missingEvidence(string $selection): array
    {
        return [
            'available' => false,
            'selection' => $selection,
            'sourceControlled' => false,
            'fullyPromotable' => false,
            'policyPublishable' => false,
            'policyVersionMatches' => false,
            'status' => 'missing',
            'freshness' => null,
            'snapshotId' => null,
            'sourceAsOfAt' => null,
        ];
    }

    /** @param array<string, int|string> $scope */
    private function assertPacketScope(FinancialPersistedSnapshotDTO $packet, array $scope): void
    {
        $snapshot = $packet->snapshot;
        if ($snapshot->scopeKey !== $scope['scopeKey']
            || $snapshot->universityId !== $scope['universityId']
            || $snapshot->cycleId !== $scope['cycleId']) {
            throw new FinancialReadModelValidationException('Financial packet is outside the requested exact scope.');
        }
    }

    /** @param array<string, int|string> $scope */
    private function assertScope(array $scope): void
    {
        if (! preg_match('/^[0-9a-f]{64}$/', (string) $scope['scopeKey'])) {
            throw new FinancialReadModelValidationException('scopeKey must be a lowercase SHA-256 digest.');
        }
        foreach (['accountId', 'organizerId', 'eventId'] as $field) {
            if ($scope[$field] < 1) {
                throw new FinancialReadModelValidationException("$field must be positive.");
            }
        }
        foreach (['universityId', 'cycleId'] as $field) {
            if (trim((string) $scope[$field]) === '') {
                throw new FinancialReadModelValidationException("$field must be non-empty.");
            }
        }
    }

    private function plan(FinancialPersistedSnapshotDTO $packet, DateTimeImmutable $cutoff): array
    {
        $plan = $packet->planRevision;
        if ($plan === null) {
            throw new FinancialReadModelValidationException('Planned-position packet is missing its plan revision.');
        }
        $this->assertNotAfterCutoff($plan->asOfAt, $cutoff, 'plan.asOfAt');
        foreach ([
            'plannedTicketProceedsCents' => $plan->plannedTicketProceedsCents,
            'plannedFundraisingGoalCents' => $plan->fundraisingGoalCents,
            'plannedGrossIncomeCents' => $plan->plannedGrossIncomeCents,
        ] as $field => $expected) {
            if (($packet->snapshot->summary[$field] ?? null) !== $expected) {
                throw new FinancialReadModelValidationException("Plan summary $field no longer matches its revision.");
            }
        }

        return [
            'asOfAt' => $plan->asOfAt->format(DATE_ATOM),
            'pricingConvention' => $plan->pricingConvention,
            'basisPointRounding' => $plan->basisPointRounding,
            'sourceIdentityKey' => $plan->sourceIdentityKey,
            'contentFingerprint' => $plan->contentFingerprint,
            'ticketQuantity' => $plan->ticketQuantity,
            'ticketCustomerPriceCents' => $plan->ticketCustomerPriceCents,
            'perTicketCommissionCents' => $plan->perTicketCommissionCents,
            'fundraisingGoalCents' => $plan->fundraisingGoalCents,
            'universityAllocationBasisPoints' => $plan->universityAllocationBasisPoints,
            'donorboxFeeBasisPoints' => $plan->donorboxFeeBasisPoints,
            'totals' => [
                'plannedTicketCustomerChargeCents' => $plan->plannedTicketCustomerChargeCents,
                'plannedCommissionCents' => $plan->plannedCommissionCents,
                'plannedTicketProceedsCents' => $plan->plannedTicketProceedsCents,
                'plannedFundraisingGoalCents' => $plan->fundraisingGoalCents,
                'plannedUniversityFundraisingAllocationCents' => $plan->plannedUniversityFundraisingAllocationCents,
                'plannedDonorboxFeeCents' => $plan->plannedDonorboxFeeCents,
                'plannedGrossIncomeCents' => $plan->plannedGrossIncomeCents,
                'plannedIncomeAfterDonorboxFeeCents' => $plan->plannedIncomeAfterDonorboxFeeCents,
            ],
        ];
    }

    private function ticket(
        FinancialPersistedSnapshotDTO $packet,
        FinancialLaunchPolicy $policy,
        DateTimeImmutable $cutoff,
    ): array {
        $summary = $packet->snapshot->summary;
        $eligibility = [
            'definition' => $this->requiredString($summary, 'eligibilityDefinition'),
            'sourceGrain' => $this->requiredString($summary, 'eligibilitySourceGrain'),
            'eligibleTransactionCount' => $this->requiredNonNegativeInt(
                $summary,
                'eligibleTransactionCount',
            ),
            'zeroPriceReviewCount' => $this->requiredNonNegativeInt(
                $summary,
                'zeroPriceReviewCount',
            ),
            'unpaidOrUnsettledCount' => $this->requiredNonNegativeInt(
                $summary,
                'unpaidOrUnsettledCount',
            ),
        ];
        if ($eligibility['sourceGrain'] !== 'spark_attendee_row') {
            throw new FinancialReadModelValidationException('Ticket eligibility source grain is unsupported.');
        }
        $actuals = [
            'recordCount' => count($packet->records),
            'quantity' => 0,
            'customerChargeCents' => 0,
            'kampProceedsCents' => 0,
            'applicationFeeCents' => 0,
            'applicationFeeActualCents' => 0,
            'applicationFeeEstimatedCents' => 0,
            'processorFeeCents' => 0,
            'processorFeeActualCents' => 0,
            'processorFeeEstimatedCents' => 0,
            'refundCents' => 0,
            'paymentReversalCents' => 0,
            'kampNetSettlementCents' => 0,
        ];
        foreach ($packet->records as $record) {
            $gross = $this->requiredMoney($record->grossCents, 'ticket.grossCents');
            $platformFee = $this->requiredMoney(
                $record->platformFeeCents,
                'ticket.platformFeeCents',
            );
            $processorFee = $this->requiredMoney(
                $record->processorFeeCents,
                'ticket.processorFeeCents',
            );
            $actuals['quantity'] = $this->checkedAdd(
                $actuals['quantity'],
                $record->quantity,
                'ticket.quantity',
            );
            $actuals['customerChargeCents'] = $this->checkedAdd(
                $actuals['customerChargeCents'],
                $gross,
                'ticket.customerChargeCents',
            );
            $actuals['kampProceedsCents'] = $this->checkedAdd(
                $actuals['kampProceedsCents'],
                $this->checkedSubtract(
                    $this->checkedSubtract($gross, $platformFee, 'ticket.kampProceedsCents'),
                    $processorFee,
                    'ticket.kampProceedsCents',
                ),
                'ticket.kampProceedsCents',
            );
            $this->addMoney($actuals, 'applicationFeeCents', $platformFee);
            $this->addMoney($actuals, 'processorFeeCents', $processorFee);
            $this->addMoney($actuals, 'refundCents', $record->refundCents ?? 0);
            $this->addMoney($actuals, 'paymentReversalCents', $record->paymentReversalCents ?? 0);
            $this->addMoney($actuals, 'kampNetSettlementCents', $record->netSettlementCents ?? 0);
            $this->addMoney(
                $actuals,
                $record->platformFeeProvenance === 'actual'
                    ? 'applicationFeeActualCents'
                    : 'applicationFeeEstimatedCents',
                $platformFee,
            );
            $this->addMoney(
                $actuals,
                $record->processorFeeProvenance === 'actual'
                    ? 'processorFeeActualCents'
                    : 'processorFeeEstimatedCents',
                $processorFee,
            );
            $this->assertNotAfterCutoff($record->sourceUpdatedAt, $cutoff, 'ticket.sourceUpdatedAt');
        }
        if ($eligibility['eligibleTransactionCount'] !== $actuals['recordCount']) {
            throw new FinancialReadModelValidationException('Ticket eligibility count no longer matches records.');
        }
        $this->assertReceiptTotal(
            $packet,
            'recordCount',
            $actuals['recordCount'],
            'Ticket receipt record count',
        );
        $this->assertReceiptTotal(
            $packet,
            'customerChargeCents',
            $actuals['customerChargeCents'],
            'Ticket receipt customer charge',
        );

        return [
            'status' => $packet->receipt?->status->value,
            'sourcePublishable' => true,
            'actuals' => $actuals,
            'diagnosticActuals' => $actuals,
            'eligibility' => $eligibility,
            'sourceStatus' => $this->sourceStatus($packet),
            'definitionMatchesPolicy' => $eligibility['definition'] === $policy->eligibleTransactionDefinition,
        ];
    }

    private function settlement(
        FinancialPersistedSnapshotDTO $packet,
        FinancialLaunchPolicy $policy,
        DateTimeImmutable $cutoff,
        array $evidence,
    ): array {
        $totals = $packet->receipt?->importedTotals ?? [];
        $recordCount = $this->requiredNonNegativeInt($totals, 'recordCount');
        if ($recordCount !== count($packet->records)) {
            throw new FinancialReadModelValidationException('Settlement receipt no longer matches records.');
        }
        $actuals = [
            'recordCount' => $recordCount,
            'customerChargeCents' => $this->requiredNonNegativeInt($totals, 'customerChargeCents'),
            'stripeProcessingFeeCents' => $this->requiredNonNegativeInt(
                $totals,
                'stripeProcessingFeeCents',
            ),
            'applicationFeeCents' => $this->requiredNonNegativeInt($totals, 'applicationFeeCents'),
            'connectedNetCents' => $this->requiredNonNegativeInt($totals, 'connectedNetCents'),
            'refundCents' => $this->requiredNonNegativeInt($totals, 'refundCents'),
            'applicationFeeRefundCents' => $this->requiredNonNegativeInt(
                $totals,
                'applicationFeeRefundCents',
            ),
            'disputeAmountCents' => $this->requiredNonNegativeInt($totals, 'disputeAmountCents'),
            'disputeFeeCents' => $this->requiredNonNegativeInt($totals, 'disputeFeeCents'),
            'connectedSettlementAfterAdjustmentsCents' => $this->requiredInt(
                $totals,
                'connectedSettlementAfterAdjustmentsCents',
            ),
        ];
        $actuals['immediateAdjustmentCents'] = $this->checkedSubtract(
            $actuals['connectedNetCents'],
            $actuals['connectedSettlementAfterAdjustmentsCents'],
            'settlement.immediateAdjustmentCents',
        );
        if ($actuals['immediateAdjustmentCents'] < 0) {
            throw new FinancialReadModelValidationException('Settlement immediate adjustments cannot be negative.');
        }
        $semantics = $policy->ticketNetSemantics(
            $actuals['customerChargeCents'],
            $actuals['connectedNetCents'],
            $actuals['stripeProcessingFeeCents'],
            $actuals['applicationFeeCents'],
        );
        $persistedSemanticStatus = $this->requiredString(
            $packet->snapshot->summary,
            'semanticStatus',
        );
        $persistedPolicyCompatible = $packet->snapshot->summary['policyCompatible'] ?? null;
        if ($persistedSemanticStatus !== $semantics['status']
            || ! is_bool($persistedPolicyCompatible)
            || $persistedPolicyCompatible !== $semantics['policyCompatible']) {
            throw new FinancialReadModelValidationException('Persisted settlement semantics no longer match money totals.');
        }
        foreach ($packet->records as $record) {
            $this->assertNotAfterCutoff($record->sourceUpdatedAt, $cutoff, 'settlement.sourceUpdatedAt');
        }

        return [
            'status' => $packet->receipt?->status->value,
            'sourcePublishable' => true,
            'policyPublishable' => $evidence['policyPublishable'] && $semantics['policyCompatible'],
            'semanticStatus' => $semantics['status'],
            'semantics' => $semantics,
            'actuals' => $actuals,
            'sourceStatus' => $this->sourceStatus($packet),
        ];
    }

    private function donation(
        FinancialPersistedSnapshotDTO $packet,
        FinancialLaunchPolicy $policy,
        DateTimeImmutable $cutoff,
        array $evidence,
    ): array {
        $statuses = $packet->receipt?->sourceTotals['includedProviderStatuses'] ?? null;
        if (! is_array($statuses) || $statuses === []) {
            throw new FinancialReadModelValidationException('Donation receipt must declare controlled provider statuses.');
        }
        $controlled = array_values(array_filter(
            $packet->records,
            static fn (FinancialSnapshotRecordDTO $record): bool => in_array($record->providerStatus, $statuses, true),
        ));
        $grossCents = 0;
        $amountRefundedCents = 0;
        $platformFeeCents = 0;
        $processorFeeCents = 0;
        $netCents = 0;
        $knownPlatformFees = true;
        $knownProcessorFees = true;
        $knownNet = true;
        foreach ($controlled as $record) {
            $grossCents = $this->checkedAdd(
                $grossCents,
                $this->requiredMoney($record->grossCents, 'donation.grossCents'),
                'donation.grossCents',
            );
            $amountRefundedCents = $this->checkedAdd(
                $amountRefundedCents,
                $this->requiredMoney($record->refundCents, 'donation.refundCents'),
                'donation.amountRefundedCents',
            );
            $knownPlatformFees = $knownPlatformFees && $record->platformFeeCents !== null;
            $knownProcessorFees = $knownProcessorFees && $record->processorFeeCents !== null;
            $knownNet = $knownNet && $record->providerNetCents !== null;
            if ($record->platformFeeCents !== null) {
                $platformFeeCents = $this->checkedAdd(
                    $platformFeeCents,
                    $record->platformFeeCents,
                    'donation.platformFeeCents',
                );
            }
            if ($record->processorFeeCents !== null) {
                $processorFeeCents = $this->checkedAdd(
                    $processorFeeCents,
                    $record->processorFeeCents,
                    'donation.processorFeeCents',
                );
            }
            if ($record->providerNetCents !== null) {
                $netCents = $this->checkedAdd(
                    $netCents,
                    $record->providerNetCents,
                    'donation.netCents',
                );
            }
            $this->assertNotAfterCutoff($record->sourceUpdatedAt, $cutoff, 'donation.sourceUpdatedAt');
        }
        $this->assertReceiptTotal($packet, 'recordCount', count($controlled), 'Donation record count');
        $this->assertReceiptTotal($packet, 'grossCents', $grossCents, 'Donation gross');
        $this->assertReceiptTotal(
            $packet,
            'amountRefundedCents',
            $amountRefundedCents,
            'Donation refunds',
        );
        $observedProcessingFeeCents = $knownPlatformFees && $knownProcessorFees
            ? $this->checkedAdd(
                $platformFeeCents,
                $processorFeeCents,
                'donation.observedProcessingFeeCents',
            )
            : null;
        $diagnosticActuals = [
            'recordCount' => count($controlled),
            'grossCents' => $grossCents,
            'amountRefundedCents' => $amountRefundedCents,
            'platformFeeCents' => $knownPlatformFees ? $platformFeeCents : null,
            'processorFeeCents' => $knownProcessorFees ? $processorFeeCents : null,
            'netCents' => $knownNet ? $netCents : null,
        ];

        return [
            'status' => $packet->receipt?->status->value,
            'sourcePublishable' => true,
            'fullyPromotable' => $evidence['fullyPromotable'],
            'grossActuals' => [
                'recordCount' => count($controlled),
                'grossCents' => $grossCents,
                'amountRefundedCents' => $amountRefundedCents,
            ],
            'netActuals' => $evidence['fullyPromotable'] && $knownPlatformFees
                && $knownProcessorFees && $knownNet
                ? $diagnosticActuals
                : null,
            'diagnosticActuals' => $diagnosticActuals,
            'observedProcessingFeeCents' => $observedProcessingFeeCents,
            'sourceStatus' => $this->sourceStatus($packet),
            'policyConfirmation' => $policy->fundraisingProcessingFeeConfirmation,
        ];
    }

    private function missingTicket(): array
    {
        return [
            'status' => 'missing',
            'sourcePublishable' => false,
            'actuals' => null,
            'diagnosticActuals' => null,
            'eligibility' => null,
            'sourceStatus' => null,
        ];
    }

    private function missingSettlement(): array
    {
        return [
            'status' => 'missing',
            'sourcePublishable' => false,
            'policyPublishable' => false,
            'semanticStatus' => 'missing_stripe_settlement',
            'semantics' => null,
            'actuals' => null,
            'sourceStatus' => null,
        ];
    }

    private function missingDonation(): array
    {
        return [
            'status' => 'missing',
            'sourcePublishable' => false,
            'fullyPromotable' => false,
            'grossActuals' => null,
            'netActuals' => null,
            'diagnosticActuals' => null,
            'observedProcessingFeeCents' => null,
            'sourceStatus' => null,
        ];
    }

    private function sourceStatus(FinancialPersistedSnapshotDTO $packet): array
    {
        return [
            'receiptId' => $packet->receipt?->sourceReceiptId,
            'status' => $packet->receipt?->status->value,
            'freshness' => $packet->receipt?->freshness->value,
            'sourceAsOfAt' => $packet->snapshot->sourceAsOfAt->format(DATE_ATOM),
            'excludedCount' => $packet->receipt?->excludedCount,
            'conflictCount' => $packet->receipt?->conflictCount,
            'discrepancyCount' => $packet->receipt?->discrepancyCount,
        ];
    }

    private function policyArray(FinancialLaunchPolicy $policy): array
    {
        return [
            'policyVersion' => $policy->policyVersion,
            'effectiveAt' => $policy->effectiveAt->format(DATE_ATOM),
            'reportingTimezone' => $policy->reportingTimezone,
            'sourceFreshnessSeconds' => $policy->sourceFreshnessSeconds,
            'ticketRevenue' => [
                'basis' => $policy->ticketRevenueBasis,
                'fixedDeductionCents' => $policy->fixedDeductionCents,
                'eligibleTransactionDefinition' => $policy->eligibleTransactionDefinition,
            ],
            'fundraising' => [
                'allocationBase' => 'gross_after_immediate_adjustments',
                'allocationBasisPoints' => $policy->fundraisingAllocationBasisPoints,
                'processingFeesReduceUniversityRevenue' => $policy->fundraisingProcessingFeesReduceRevenue,
                'processingFeeRationale' => $policy->fundraisingProcessingFeeRationale,
                'processingFeeConfirmation' => $policy->fundraisingProcessingFeeConfirmation,
            ],
            'adjustments' => ['timing' => 'immediate'],
        ];
    }

    private function assertReceiptTotal(
        FinancialPersistedSnapshotDTO $packet,
        string $field,
        int $expected,
        string $label,
    ): void {
        $actual = $packet->receipt?->importedTotals[$field] ?? null;
        if (! is_int($actual) || $actual !== $expected) {
            throw new FinancialReadModelValidationException("$label no longer matches persisted records.");
        }
    }

    /** @param array<string, mixed> $values */
    private function requiredString(array $values, string $field): string
    {
        $value = $values[$field] ?? null;
        if (! is_string($value) || trim($value) === '') {
            throw new FinancialReadModelValidationException("$field must be a non-empty string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function requiredInt(array $values, string $field): int
    {
        $value = $values[$field] ?? null;
        if (! is_int($value) || abs($value) > self::MAX_SAFE_INTEGER) {
            throw new FinancialReadModelValidationException("$field must be a safe integer.");
        }

        return $value;
    }

    /** @param array<string, mixed> $values */
    private function requiredNonNegativeInt(array $values, string $field): int
    {
        $value = $this->requiredInt($values, $field);
        if ($value < 0) {
            throw new FinancialReadModelValidationException("$field must be non-negative.");
        }

        return $value;
    }

    private function requiredMoney(?int $value, string $field): int
    {
        if ($value === null || $value < 0 || $value > self::MAX_SAFE_INTEGER) {
            throw new FinancialReadModelValidationException("$field must be a non-negative safe integer.");
        }

        return $value;
    }

    /** @param array<string, int> $totals */
    private function addMoney(array &$totals, string $field, int $value): void
    {
        $totals[$field] = $this->checkedAdd($totals[$field], $value, $field);
    }

    private function checkedAdd(int $left, int $right, string $field): int
    {
        $value = $left + $right;
        if (abs($value) > self::MAX_SAFE_INTEGER) {
            throw new FinancialReadModelValidationException("$field exceeds safe integer range.");
        }

        return $value;
    }

    private function checkedSubtract(int $left, int $right, string $field): int
    {
        $value = $left - $right;
        if (abs($value) > self::MAX_SAFE_INTEGER) {
            throw new FinancialReadModelValidationException("$field exceeds safe integer range.");
        }

        return $value;
    }

    private function assertNotAfterCutoff(
        DateTimeInterface $value,
        DateTimeImmutable $cutoff,
        string $field,
    ): void {
        if (DateTimeImmutable::createFromInterface($value) > $cutoff) {
            throw new FinancialReadModelValidationException("$field cannot follow cutoffAt.");
        }
    }
}
