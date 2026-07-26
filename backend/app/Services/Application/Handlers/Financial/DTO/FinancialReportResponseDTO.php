<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Financial\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\Services\Domain\Financial\DTOs\FinancialPersistedReceiptDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialReadModelDTO;

class FinancialReportResponseDTO extends BaseDataObject
{
    /**
     * @param  array<string, mixed>  $scope
     * @param  array<string, mixed>  $financialPolicy
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $tickets
     * @param  array<string, mixed>  $donations
     * @param  array<string, int|null>  $variances
     * @param  array<string, mixed>  $currentPosition
     * @param  array<string, array<string, bool|string|null>>  $sourceEvidence
     * @param  array<string, mixed>|null  $reconciliation
     */
    public function __construct(
        public readonly array $scope,
        public readonly string $cutoffAt,
        public readonly string $generatedAt,
        public readonly string $reportingTimezone,
        public readonly array $financialPolicy,
        public readonly array $plan,
        public readonly array $tickets,
        public readonly array $donations,
        public readonly array $variances,
        public readonly array $currentPosition,
        public readonly array $sourceEvidence,
        public readonly bool $publishable,
        public readonly ?array $reconciliation,
    ) {}

    public static function fromReadModel(FinancialReadModelDTO $report): self
    {
        return new self(
            scope: self::fields($report->scope, [
                'eventId' => 'event_id',
                'universityId' => 'university_id',
                'cycleId' => 'cycle_id',
            ]),
            cutoffAt: $report->cutoffAt->format(DATE_ATOM),
            generatedAt: $report->generatedAt->format(DATE_ATOM),
            reportingTimezone: $report->reportingTimezone,
            financialPolicy: self::financialPolicy($report->financialPolicy),
            plan: self::plan($report->plan),
            tickets: self::tickets($report->tickets),
            donations: self::donations($report->donations),
            variances: self::fields($report->variances, [
                'ticketQuantity' => 'ticket_quantity',
                'ticketProceedsCents' => 'ticket_proceeds_cents',
                'fundraisingGrossCents' => 'fundraising_gross_cents',
            ]),
            currentPosition: self::currentPosition($report->currentPosition),
            sourceEvidence: [
                'plan' => self::evidence($report->sourceEvidence['plan'] ?? []),
                'tickets' => self::evidence($report->sourceEvidence['tickets'] ?? []),
                'settlement' => self::evidence($report->sourceEvidence['settlement'] ?? []),
                'donations' => self::evidence($report->sourceEvidence['donations'] ?? []),
            ],
            publishable: $report->publishable,
            reconciliation: self::reconciliation($report->reconciliation),
        );
    }

    private static function financialPolicy(array $policy): array
    {
        return [
            ...self::fields($policy, [
                'policyVersion' => 'policy_version',
                'effectiveAt' => 'effective_at',
                'reportingTimezone' => 'reporting_timezone',
                'sourceFreshnessSeconds' => 'source_freshness_seconds',
            ]),
            'ticket_revenue' => self::fields($policy['ticketRevenue'] ?? null, [
                'basis' => 'basis',
                'fixedDeductionCents' => 'fixed_deduction_cents',
                'eligibleTransactionDefinition' => 'eligible_transaction_definition',
            ]),
            'fundraising' => self::fields($policy['fundraising'] ?? null, [
                'allocationBase' => 'allocation_base',
                'allocationBasisPoints' => 'allocation_basis_points',
                'processingFeesReduceUniversityRevenue' => 'processing_fees_reduce_university_revenue',
                'processingFeeRationale' => 'processing_fee_rationale',
                'processingFeeConfirmation' => 'processing_fee_confirmation',
            ]),
            'adjustments' => self::fields($policy['adjustments'] ?? null, [
                'timing' => 'timing',
            ]),
        ];
    }

    private static function plan(array $plan): array
    {
        return [
            ...self::fields($plan, [
                'asOfAt' => 'as_of_at',
                'pricingConvention' => 'pricing_convention',
                'basisPointRounding' => 'basis_point_rounding',
                'ticketQuantity' => 'ticket_quantity',
                'ticketCustomerPriceCents' => 'ticket_customer_price_cents',
                'perTicketCommissionCents' => 'per_ticket_commission_cents',
                'fundraisingGoalCents' => 'fundraising_goal_cents',
                'universityAllocationBasisPoints' => 'university_allocation_basis_points',
                'donorboxFeeBasisPoints' => 'donorbox_fee_basis_points',
            ]),
            'totals' => self::fields($plan['totals'] ?? null, [
                'plannedTicketCustomerChargeCents' => 'planned_ticket_customer_charge_cents',
                'plannedCommissionCents' => 'planned_commission_cents',
                'plannedTicketProceedsCents' => 'planned_ticket_proceeds_cents',
                'plannedFundraisingGoalCents' => 'planned_fundraising_goal_cents',
                'plannedUniversityFundraisingAllocationCents' => 'planned_university_fundraising_allocation_cents',
                'plannedDonorboxFeeCents' => 'planned_donorbox_fee_cents',
                'plannedGrossIncomeCents' => 'planned_gross_income_cents',
                'plannedIncomeAfterDonorboxFeeCents' => 'planned_income_after_donorbox_fee_cents',
            ]),
        ];
    }

    private static function tickets(array $tickets): array
    {
        $settlement = $tickets['settlement'] ?? [];

        return [
            ...self::fields($tickets, [
                'status' => 'status',
                'sourcePublishable' => 'source_publishable',
                'policyPublishable' => 'policy_publishable',
                'policyValidationStatus' => 'policy_validation_status',
                'eligibilityDefinitionMatches' => 'eligibility_definition_matches',
                'settlementCountMatches' => 'settlement_count_matches',
                'recognizedRevenueCents' => 'recognized_revenue_cents',
            ]),
            'eligibility' => self::eligibility($tickets['eligibility'] ?? null),
            'actuals' => self::ticketActuals($tickets['actuals'] ?? null),
            'source_status' => self::sourceStatus($tickets['sourceStatus'] ?? null),
            'settlement' => [
                ...self::fields($settlement, [
                    'status' => 'status',
                    'sourcePublishable' => 'source_publishable',
                    'policyPublishable' => 'policy_publishable',
                ]),
                'actuals' => self::settlementActuals($settlement['actuals'] ?? null),
                'source_status' => self::sourceStatus($settlement['sourceStatus'] ?? null),
            ],
        ];
    }

    private static function donations(array $donations): array
    {
        return [
            ...self::fields($donations, [
                'status' => 'status',
                'sourcePublishable' => 'source_publishable',
                'fullyPromotable' => 'fully_promotable',
                'policyPublishable' => 'policy_publishable',
                'recognizedRevenueCents' => 'recognized_revenue_cents',
                'allocationBaseCents' => 'allocation_base_cents',
                'validationRequired' => 'validation_required',
            ]),
            'gross_actuals' => self::fields($donations['grossActuals'] ?? null, [
                'recordCount' => 'record_count',
                'grossCents' => 'gross_cents',
                'amountRefundedCents' => 'amount_refunded_cents',
            ]),
            'net_actuals' => self::donationActuals($donations['netActuals'] ?? null),
            'source_status' => self::sourceStatus($donations['sourceStatus'] ?? null),
        ];
    }

    private static function currentPosition(array $currentPosition): array
    {
        return [
            ...self::fields($currentPosition, [
                'knownCents' => 'known_cents',
                'complete' => 'complete',
                'missingOrUnpublishableSources' => 'missing_or_unpublishable_sources',
            ]),
            'components' => array_map(
                static fn (array $component): array => self::fields($component, [
                    'source' => 'source',
                    'cents' => 'cents',
                ]),
                $currentPosition['components'] ?? [],
            ),
        ];
    }

    private static function evidence(array $evidence): array
    {
        return self::fields($evidence, [
            'available' => 'available',
            'selection' => 'selection',
            'sourceControlled' => 'source_controlled',
            'fullyPromotable' => 'fully_promotable',
            'policyPublishable' => 'policy_publishable',
            'policyVersionMatches' => 'policy_version_matches',
            'status' => 'status',
            'freshness' => 'freshness',
            'sourceAsOfAt' => 'source_as_of_at',
        ]);
    }

    private static function eligibility(?array $eligibility): ?array
    {
        return self::fields($eligibility, [
            'definition' => 'definition',
            'sourceGrain' => 'source_grain',
            'eligibleTransactionCount' => 'eligible_transaction_count',
            'zeroPriceReviewCount' => 'zero_price_review_count',
            'unpaidOrUnsettledCount' => 'unpaid_or_unsettled_count',
        ]);
    }

    private static function ticketActuals(?array $actuals): ?array
    {
        return self::fields($actuals, [
            'recordCount' => 'record_count',
            'quantity' => 'quantity',
            'customerChargeCents' => 'customer_charge_cents',
            'kampProceedsCents' => 'kamp_proceeds_cents',
            'applicationFeeCents' => 'application_fee_cents',
            'applicationFeeActualCents' => 'application_fee_actual_cents',
            'applicationFeeEstimatedCents' => 'application_fee_estimated_cents',
            'processorFeeCents' => 'processor_fee_cents',
            'processorFeeActualCents' => 'processor_fee_actual_cents',
            'processorFeeEstimatedCents' => 'processor_fee_estimated_cents',
            'refundCents' => 'refund_cents',
            'paymentReversalCents' => 'payment_reversal_cents',
            'kampNetSettlementCents' => 'kamp_net_settlement_cents',
        ]);
    }

    private static function settlementActuals(?array $actuals): ?array
    {
        return self::fields($actuals, [
            'recordCount' => 'record_count',
            'customerChargeCents' => 'customer_charge_cents',
            'stripeProcessingFeeCents' => 'stripe_processing_fee_cents',
            'applicationFeeCents' => 'application_fee_cents',
            'connectedNetCents' => 'connected_net_cents',
            'refundCents' => 'refund_cents',
            'applicationFeeRefundCents' => 'application_fee_refund_cents',
            'disputeAmountCents' => 'dispute_amount_cents',
            'disputeFeeCents' => 'dispute_fee_cents',
            'connectedSettlementAfterAdjustmentsCents' => 'connected_settlement_after_adjustments_cents',
            'immediateAdjustmentCents' => 'immediate_adjustment_cents',
        ]);
    }

    private static function donationActuals(?array $actuals): ?array
    {
        return self::fields($actuals, [
            'recordCount' => 'record_count',
            'grossCents' => 'gross_cents',
            'amountRefundedCents' => 'amount_refunded_cents',
            'platformFeeCents' => 'platform_fee_cents',
            'processorFeeCents' => 'processor_fee_cents',
            'netCents' => 'net_cents',
        ]);
    }

    private static function sourceStatus(?array $status): ?array
    {
        return self::fields($status, [
            'status' => 'status',
            'freshness' => 'freshness',
            'sourceAsOfAt' => 'source_as_of_at',
        ]);
    }

    private static function reconciliation(?array $reconciliation): ?array
    {
        if ($reconciliation === null) {
            return null;
        }

        $policyValidation = $reconciliation['policyValidation'] ?? [];
        $fundraising = $policyValidation['fundraisingRecognition'] ?? null;

        return [
            'ticket_receipt' => self::receipt($reconciliation['ticketReceipt'] ?? null),
            'ticket_settlement_receipt' => self::receipt(
                $reconciliation['ticketSettlementReceipt'] ?? null,
            ),
            'donation_receipt' => self::receipt($reconciliation['donationReceipt'] ?? null),
            'ticket_observed_actuals' => self::ticketActuals(
                $reconciliation['ticketObservedActuals'] ?? null,
            ),
            'ticket_settlement_observed_actuals' => self::settlementActuals(
                $reconciliation['ticketSettlementObservedActuals'] ?? null,
            ),
            'donation_observed_actuals' => self::donationActuals(
                $reconciliation['donationObservedActuals'] ?? null,
            ),
            'policy_validation' => [
                'ticket_net_semantics' => self::fields(
                    $policyValidation['ticketNetSemantics'] ?? null,
                    [
                        'customerChargeCents' => 'customer_charge_cents',
                        'stripeNetAmountCents' => 'stripe_net_amount_cents',
                        'observedProcessorFeeCents' => 'observed_processor_fee_cents',
                        'observedApplicationFeeCents' => 'observed_application_fee_cents',
                        'reconstructedChargeCents' => 'reconstructed_charge_cents',
                        'reconstructedWithoutApplicationFeeCents' => 'reconstructed_without_application_fee_cents',
                        'applicationFeeAppearsIncludedInNet' => 'application_fee_appears_included_in_net',
                        'fixedDeductionStillRequired' => 'fixed_deduction_still_required',
                        'policyCompatible' => 'policy_compatible',
                        'status' => 'status',
                    ],
                ),
                'fundraising_processing_fee_confirmation' => $policyValidation['fundraisingProcessingFeeConfirmation'] ?? null,
                'fundraising_recognition' => self::fields($fundraising, [
                    'allocationBaseCents' => 'allocation_base_cents',
                    'candidateRevenueCents' => 'candidate_revenue_cents',
                    'validationRequired' => 'validation_required',
                    'policyPublishable' => 'policy_publishable',
                ]),
            ],
        ];
    }

    private static function receipt(mixed $receipt): ?array
    {
        if (! $receipt instanceof FinancialPersistedReceiptDTO) {
            return null;
        }

        return [
            'persistence_receipt_id' => $receipt->persistenceReceiptId,
            'source_receipt_id' => $receipt->sourceReceiptId,
            'snapshot_id' => $receipt->snapshotId,
            'append_classification' => $receipt->appendClassification->value,
            'status' => $receipt->status->value,
            'freshness' => $receipt->freshness->value,
            'source_publishable' => $receipt->sourcePublishable,
            'policy_publishable' => $receipt->policyPublishable,
            'promotion_eligible' => $receipt->promotionEligible,
            'source_record_count' => $receipt->sourceRecordCount,
            'imported_record_count' => $receipt->importedRecordCount,
            'excluded_count' => $receipt->excludedCount,
            'conflict_count' => $receipt->conflictCount,
            'discrepancy_count' => $receipt->discrepancyCount,
            'source_totals' => $receipt->sourceTotals,
            'imported_totals' => $receipt->importedTotals,
            'discrepancies' => array_map(
                static fn (array $discrepancy): array => self::fields($discrepancy, [
                    'field' => 'field',
                    'sourceValue' => 'source_value',
                    'importedValue' => 'imported_value',
                    'delta' => 'delta',
                ]),
                $receipt->discrepancies,
            ),
            'source_as_of_at' => $receipt->sourceAsOfAt->format(DATE_ATOM),
            'generated_at' => $receipt->generatedAt->format(DATE_ATOM),
            'recorded_at' => $receipt->recordedAt->format(DATE_ATOM),
        ];
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @param  array<string, string>  $mapping
     * @return array<string, mixed>|null
     */
    private static function fields(?array $values, array $mapping): ?array
    {
        if ($values === null) {
            return null;
        }

        $result = [];
        foreach ($mapping as $source => $target) {
            $result[$target] = $values[$source] ?? null;
        }

        return $result;
    }
}
