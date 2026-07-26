<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Financial;

use DateTimeImmutable;
use HiEvents\Exceptions\FinancialReadModelValidationException;

readonly class FinancialLaunchPolicy
{
    public const SPARK_ELIGIBLE_TRANSACTION_DEFINITION =
        'one_unique_mapped_spark_attendee_row_with_paid_true_and_customer_charge_above_zero';

    private const MAX_SAFE_INTEGER = 9_007_199_254_740_991;

    public function __construct(
        public string $policyVersion,
        public DateTimeImmutable $effectiveAt,
        public string $reportingTimezone,
        public int $sourceFreshnessSeconds,
        public string $ticketRevenueBasis,
        public int $fixedDeductionCents,
        public string $eligibleTransactionDefinition,
        public int $fundraisingAllocationBasisPoints,
        public bool $fundraisingProcessingFeesReduceRevenue,
        public string $fundraisingProcessingFeeRationale,
        public string $fundraisingProcessingFeeConfirmation,
    ) {
        $this->assertNonEmpty($policyVersion, 'policyVersion');
        if ($reportingTimezone !== 'America/Phoenix') {
            throw new FinancialReadModelValidationException('reportingTimezone must be America/Phoenix.');
        }
        if ($sourceFreshnessSeconds < 1) {
            throw new FinancialReadModelValidationException('sourceFreshnessSeconds must be positive.');
        }
        if ($ticketRevenueBasis !== 'stripe_net_minus_fixed_per_transaction') {
            throw new FinancialReadModelValidationException('ticketRevenueBasis is unsupported.');
        }
        $this->assertNonNegativeSafeInteger($fixedDeductionCents, 'fixedDeductionCents');
        $this->assertNonEmpty($eligibleTransactionDefinition, 'eligibleTransactionDefinition');
        if ($fundraisingAllocationBasisPoints < 0 || $fundraisingAllocationBasisPoints > 10_000) {
            throw new FinancialReadModelValidationException('fundraisingAllocationBasisPoints must be between 0 and 10000.');
        }
        $this->assertNonEmpty(
            $fundraisingProcessingFeeRationale,
            'fundraisingProcessingFeeRationale',
        );
        if (! in_array($fundraisingProcessingFeeConfirmation, ['confirmed', 'unconfirmed'], true)) {
            throw new FinancialReadModelValidationException('fundraisingProcessingFeeConfirmation is unsupported.');
        }
    }

    public static function current(): self
    {
        return new self(
            policyVersion: '2026-07-25.2',
            effectiveAt: new DateTimeImmutable('2026-07-25T12:00:00-07:00'),
            reportingTimezone: 'America/Phoenix',
            sourceFreshnessSeconds: 86_400,
            ticketRevenueBasis: 'stripe_net_minus_fixed_per_transaction',
            fixedDeductionCents: 600,
            eligibleTransactionDefinition: self::SPARK_ELIGIBLE_TRANSACTION_DEFINITION,
            fundraisingAllocationBasisPoints: 4_000,
            fundraisingProcessingFeesReduceRevenue: false,
            fundraisingProcessingFeeRationale: 'possible_buyer_paid_unconfirmed',
            fundraisingProcessingFeeConfirmation: 'unconfirmed',
        );
    }

    /** @return array<string, bool|int|string> */
    public function ticketRevenue(
        int $stripeNetAmountCents,
        int $eligibleTransactionCount,
        int $immediateAdjustmentCents,
    ): array {
        $this->assertNonNegativeSafeInteger($stripeNetAmountCents, 'stripeNetAmountCents');
        $this->assertNonNegativeSafeInteger($eligibleTransactionCount, 'eligibleTransactionCount');
        $this->assertNonNegativeSafeInteger($immediateAdjustmentCents, 'immediateAdjustmentCents');

        $fixedDeductionCents = $this->checkedMultiply(
            $this->fixedDeductionCents,
            $eligibleTransactionCount,
            'fixedDeductionCents',
        );
        $recognizedRevenueCents = $this->checkedSubtract(
            $this->checkedSubtract(
                $stripeNetAmountCents,
                $fixedDeductionCents,
                'recognizedTicketRevenueCents',
            ),
            $immediateAdjustmentCents,
            'recognizedTicketRevenueCents',
        );

        return [
            'basis' => $this->ticketRevenueBasis,
            'stripeNetAmountCents' => $stripeNetAmountCents,
            'eligibleTransactionCount' => $eligibleTransactionCount,
            'fixedDeductionPerTransactionCents' => $this->fixedDeductionCents,
            'fixedDeductionCents' => $fixedDeductionCents,
            'immediateAdjustmentCents' => $immediateAdjustmentCents,
            'recognizedRevenueCents' => $recognizedRevenueCents,
            'policyVersion' => $this->policyVersion,
            'eligibleTransactionDefinition' => $this->eligibleTransactionDefinition,
        ];
    }

    /** @return array<string, bool|int|string|null> */
    public function fundraisingRevenue(
        int $grossRaisedCents,
        int $immediateAdjustmentCents,
        ?int $observedProcessingFeeCents,
    ): array {
        $this->assertNonNegativeSafeInteger($grossRaisedCents, 'grossRaisedCents');
        $this->assertNonNegativeSafeInteger($immediateAdjustmentCents, 'immediateAdjustmentCents');
        if ($immediateAdjustmentCents > $grossRaisedCents) {
            throw new FinancialReadModelValidationException('immediateAdjustmentCents cannot exceed grossRaisedCents.');
        }
        if ($observedProcessingFeeCents !== null) {
            $this->assertNonNegativeSafeInteger(
                $observedProcessingFeeCents,
                'observedProcessingFeeCents',
            );
        }

        $allocationBaseCents = $grossRaisedCents - $immediateAdjustmentCents;
        $universityAllocationCents = $this->basisPoints(
            $allocationBaseCents,
            $this->fundraisingAllocationBasisPoints,
        );
        $recognizedRevenueCents = $this->fundraisingProcessingFeesReduceRevenue
            ? $this->checkedSubtract(
                $universityAllocationCents,
                $observedProcessingFeeCents ?? 0,
                'recognizedFundraisingRevenueCents',
            )
            : $universityAllocationCents;

        return [
            'allocationBase' => 'gross_after_immediate_adjustments',
            'grossRaisedCents' => $grossRaisedCents,
            'immediateAdjustmentCents' => $immediateAdjustmentCents,
            'allocationBaseCents' => $allocationBaseCents,
            'allocationBasisPoints' => $this->fundraisingAllocationBasisPoints,
            'universityAllocationCents' => $universityAllocationCents,
            'observedProcessingFeeCents' => $observedProcessingFeeCents,
            'processingFeesReduceUniversityRevenue' => $this->fundraisingProcessingFeesReduceRevenue,
            'processingFeeRationale' => $this->fundraisingProcessingFeeRationale,
            'processingFeeConfirmation' => $this->fundraisingProcessingFeeConfirmation,
            'recognizedRevenueCents' => $recognizedRevenueCents,
            'validationRequired' => $this->fundraisingProcessingFeeConfirmation !== 'confirmed',
            'policyVersion' => $this->policyVersion,
        ];
    }

    /** @return array<string, bool|int|string> */
    public function ticketNetSemantics(
        int $customerChargeCents,
        int $stripeNetAmountCents,
        int $observedProcessorFeeCents,
        int $observedApplicationFeeCents,
    ): array {
        foreach (compact(
            'customerChargeCents',
            'stripeNetAmountCents',
            'observedProcessorFeeCents',
            'observedApplicationFeeCents',
        ) as $field => $value) {
            $this->assertNonNegativeSafeInteger($value, $field);
        }

        $reconstructedChargeCents = $this->checkedAdd(
            $this->checkedAdd(
                $stripeNetAmountCents,
                $observedProcessorFeeCents,
                'reconstructedChargeCents',
            ),
            $observedApplicationFeeCents,
            'reconstructedChargeCents',
        );
        $reconstructedWithoutApplicationFeeCents = $this->checkedAdd(
            $stripeNetAmountCents,
            $observedProcessorFeeCents,
            'reconstructedWithoutApplicationFeeCents',
        );
        $applicationFeeAlreadyDeducted = $reconstructedChargeCents === $customerChargeCents;
        $fixedDeductionStillRequired =
            $reconstructedWithoutApplicationFeeCents === $customerChargeCents;
        $status = $applicationFeeAlreadyDeducted
            ? 'application_fee_already_deducted'
            : ($fixedDeductionStillRequired ? 'fixed_deduction_still_required' : 'unresolved');

        return [
            'customerChargeCents' => $customerChargeCents,
            'stripeNetAmountCents' => $stripeNetAmountCents,
            'observedProcessorFeeCents' => $observedProcessorFeeCents,
            'observedApplicationFeeCents' => $observedApplicationFeeCents,
            'reconstructedChargeCents' => $reconstructedChargeCents,
            'reconstructedWithoutApplicationFeeCents' => $reconstructedWithoutApplicationFeeCents,
            'applicationFeeAppearsIncludedInNet' => $applicationFeeAlreadyDeducted,
            'fixedDeductionStillRequired' => $fixedDeductionStillRequired,
            'policyCompatible' => $status === 'fixed_deduction_still_required',
            'status' => $status,
        ];
    }

    private function basisPoints(int $amountCents, int $basisPoints): int
    {
        $quotient = intdiv($amountCents, 10_000);
        $remainder = $amountCents % 10_000;

        return $this->checkedAdd(
            $this->checkedMultiply($quotient, $basisPoints, 'basisPointsAmount'),
            intdiv(($remainder * $basisPoints) + 5_000, 10_000),
            'basisPointsAmount',
        );
    }

    private function checkedAdd(int $left, int $right, string $field): int
    {
        $value = $left + $right;
        $this->assertSafeInteger($value, $field);

        return $value;
    }

    private function checkedSubtract(int $left, int $right, string $field): int
    {
        $value = $left - $right;
        $this->assertSafeInteger($value, $field);

        return $value;
    }

    private function checkedMultiply(int $left, int $right, string $field): int
    {
        if ($left !== 0 && $right > intdiv(self::MAX_SAFE_INTEGER, $left)) {
            throw new FinancialReadModelValidationException("$field exceeds safe integer range.");
        }
        $value = $left * $right;
        $this->assertSafeInteger($value, $field);

        return $value;
    }

    private function assertSafeInteger(int $value, string $field): void
    {
        if (abs($value) > self::MAX_SAFE_INTEGER) {
            throw new FinancialReadModelValidationException("$field exceeds safe integer range.");
        }
    }

    private function assertNonNegativeSafeInteger(int $value, string $field): void
    {
        if ($value < 0) {
            throw new FinancialReadModelValidationException("$field must be non-negative.");
        }
        $this->assertSafeInteger($value, $field);
    }

    private function assertNonEmpty(string $value, string $field): void
    {
        if (trim($value) === '') {
            throw new FinancialReadModelValidationException("$field must be non-empty.");
        }
    }
}
