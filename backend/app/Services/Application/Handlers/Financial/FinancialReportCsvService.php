<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Financial;

use HiEvents\Exceptions\FinancialReadModelValidationException;
use HiEvents\Services\Application\Handlers\Financial\DTO\FinancialReportResponseDTO;

class FinancialReportCsvService
{
    public const WITHHELD_VALUE = 'WITHHELD';

    /** @return list<int|string> */
    public function headers(): array
    {
        return $this->row('section', 'metric', 'value', 'status');
    }

    /** @return list<list<int|string>> */
    public function rows(FinancialReportResponseDTO $report): array
    {
        if ($report->reconciliation !== null) {
            throw new FinancialReadModelValidationException(
                'Financial CSV exports cannot contain reconciliation details.',
            );
        }

        $policy = $report->financialPolicy;
        $ticketPolicy = $policy['ticket_revenue'] ?? null;
        $fundraisingPolicy = $policy['fundraising'] ?? null;
        $adjustments = $policy['adjustments'] ?? null;
        $plan = $report->plan;
        $planTotals = $plan['totals'] ?? null;
        $tickets = $report->tickets;
        $ticketActuals = $tickets['actuals'] ?? null;
        $ticketEligibility = $tickets['eligibility'] ?? null;
        $settlement = $tickets['settlement'] ?? null;
        $settlementActuals = is_array($settlement) ? ($settlement['actuals'] ?? null) : null;
        $donations = $report->donations;
        $donationGrossActuals = $donations['gross_actuals'] ?? null;
        $position = $report->currentPosition;

        $ticketStatus = $this->status($tickets['status'] ?? null);
        $settlementStatus = $this->status(
            is_array($settlement) ? ($settlement['status'] ?? null) : null,
        );
        $donationStatus = $this->status($donations['status'] ?? null);

        return [
            $this->row('scope', 'event_id', $report->scope['event_id'] ?? null, 'configured'),
            $this->row('scope', 'university_id', $report->scope['university_id'] ?? null, 'configured'),
            $this->row('scope', 'cycle_id', $report->scope['cycle_id'] ?? null, 'configured'),
            $this->row('report', 'cutoff_at', $report->cutoffAt, 'configured'),
            $this->row('report', 'generated_at', $report->generatedAt, 'configured'),
            $this->row('report', 'reporting_timezone', $report->reportingTimezone, 'configured'),
            $this->row('policy', 'version', $policy['policy_version'] ?? null, 'configured'),
            $this->row('policy', 'ticket_revenue_basis', $this->field($ticketPolicy, 'basis'), 'configured'),
            $this->row('policy', 'ticket_fixed_deduction_cents', $this->field($ticketPolicy, 'fixed_deduction_cents'), 'configured'),
            $this->row('policy', 'eligible_transaction_definition', $this->field($ticketPolicy, 'eligible_transaction_definition'), 'configured'),
            $this->row('policy', 'fundraising_allocation_basis_points', $this->field($fundraisingPolicy, 'allocation_basis_points'), 'configured'),
            $this->row('policy', 'processing_fees_reduce_university_revenue', $this->field($fundraisingPolicy, 'processing_fees_reduce_university_revenue'), 'configured'),
            $this->row('policy', 'processing_fee_confirmation', $this->field($fundraisingPolicy, 'processing_fee_confirmation'), 'configured'),
            $this->row('policy', 'adjustment_timing', $this->field($adjustments, 'timing'), 'configured'),
            $this->row('plan', 'as_of_at', $plan['as_of_at'] ?? null, 'planned'),
            $this->row('plan', 'ticket_quantity', $plan['ticket_quantity'] ?? null, 'planned'),
            $this->row('plan', 'ticket_proceeds_cents', $this->field($planTotals, 'planned_ticket_proceeds_cents'), 'planned'),
            $this->row('plan', 'fundraising_goal_cents', $this->field($planTotals, 'planned_fundraising_goal_cents'), 'planned'),
            $this->row('plan', 'gross_income_cents', $this->field($planTotals, 'planned_gross_income_cents'), 'planned'),
            $this->row('tickets', 'status', $tickets['status'] ?? null, $ticketStatus),
            $this->row('tickets', 'eligible_transaction_count', $this->field($ticketEligibility, 'eligible_transaction_count'), $ticketStatus),
            $this->row('tickets', 'policy_validation_status', $tickets['policy_validation_status'] ?? null, $this->publishStatus($tickets['policy_publishable'] ?? false)),
            $this->row('tickets', 'recognized_revenue_cents', $tickets['recognized_revenue_cents'] ?? null, $this->publishStatus($tickets['policy_publishable'] ?? false)),
            $this->row('tickets', 'quantity', $this->field($ticketActuals, 'quantity'), $this->actualStatus($ticketActuals, $ticketStatus)),
            $this->row('tickets', 'customer_charge_cents', $this->field($ticketActuals, 'customer_charge_cents'), $this->actualStatus($ticketActuals, $ticketStatus)),
            $this->row('tickets', 'application_fee_cents', $this->field($ticketActuals, 'application_fee_cents'), $this->actualStatus($ticketActuals, $ticketStatus)),
            $this->row('tickets', 'processor_fee_cents', $this->field($ticketActuals, 'processor_fee_cents'), $this->actualStatus($ticketActuals, $ticketStatus)),
            $this->row('tickets', 'refund_cents', $this->field($ticketActuals, 'refund_cents'), $this->actualStatus($ticketActuals, $ticketStatus)),
            $this->row('tickets', 'kamp_net_settlement_cents', $this->field($ticketActuals, 'kamp_net_settlement_cents'), $this->actualStatus($ticketActuals, $ticketStatus)),
            $this->row('stripe_settlement', 'status', $this->field($settlement, 'status'), $settlementStatus),
            $this->row('stripe_settlement', 'customer_charge_cents', $this->field($settlementActuals, 'customer_charge_cents'), $this->actualStatus($settlementActuals, $settlementStatus)),
            $this->row('stripe_settlement', 'processing_fee_cents', $this->field($settlementActuals, 'stripe_processing_fee_cents'), $this->actualStatus($settlementActuals, $settlementStatus)),
            $this->row('stripe_settlement', 'application_fee_cents', $this->field($settlementActuals, 'application_fee_cents'), $this->actualStatus($settlementActuals, $settlementStatus)),
            $this->row('stripe_settlement', 'connected_net_cents', $this->field($settlementActuals, 'connected_net_cents'), $this->actualStatus($settlementActuals, $settlementStatus)),
            $this->row('stripe_settlement', 'refund_cents', $this->field($settlementActuals, 'refund_cents'), $this->actualStatus($settlementActuals, $settlementStatus)),
            $this->row('stripe_settlement', 'application_fee_refund_cents', $this->field($settlementActuals, 'application_fee_refund_cents'), $this->actualStatus($settlementActuals, $settlementStatus)),
            $this->row('stripe_settlement', 'dispute_amount_cents', $this->field($settlementActuals, 'dispute_amount_cents'), $this->actualStatus($settlementActuals, $settlementStatus)),
            $this->row('stripe_settlement', 'dispute_fee_cents', $this->field($settlementActuals, 'dispute_fee_cents'), $this->actualStatus($settlementActuals, $settlementStatus)),
            $this->row('stripe_settlement', 'immediate_adjustment_cents', $this->field($settlementActuals, 'immediate_adjustment_cents'), $this->actualStatus($settlementActuals, $settlementStatus)),
            $this->row('stripe_settlement', 'connected_after_adjustments_cents', $this->field($settlementActuals, 'connected_settlement_after_adjustments_cents'), $this->actualStatus($settlementActuals, $settlementStatus)),
            $this->row('donations', 'status', $donations['status'] ?? null, $donationStatus),
            $this->row('donations', 'gross_cents', $this->field($donationGrossActuals, 'gross_cents'), $this->actualStatus($donationGrossActuals, $donationStatus)),
            $this->row('donations', 'refund_cents', $this->field($donationGrossActuals, 'amount_refunded_cents'), $this->actualStatus($donationGrossActuals, $donationStatus)),
            $this->row('donations', 'recognized_revenue_cents', $donations['recognized_revenue_cents'] ?? null, $this->publishStatus($donations['policy_publishable'] ?? false)),
            $this->row('variance', 'ticket_quantity', $report->variances['ticket_quantity'] ?? null, $this->valueStatus($report->variances['ticket_quantity'] ?? null, 'calculated')),
            $this->row('variance', 'ticket_proceeds_cents', $report->variances['ticket_proceeds_cents'] ?? null, $this->valueStatus($report->variances['ticket_proceeds_cents'] ?? null, 'calculated')),
            $this->row('variance', 'fundraising_gross_cents', $report->variances['fundraising_gross_cents'] ?? null, $this->valueStatus($report->variances['fundraising_gross_cents'] ?? null, 'calculated')),
            $this->row('position', 'known_cents', $position['known_cents'] ?? null, $report->publishable ? 'publishable' : 'incomplete'),
            $this->row('position', 'complete', $position['complete'] ?? null, $report->publishable ? 'publishable' : 'incomplete'),
            $this->row('position', 'publishable', $report->publishable, $report->publishable ? 'publishable' : 'incomplete'),
        ];
    }

    private function field(mixed $values, string $key): mixed
    {
        return is_array($values) ? ($values[$key] ?? null) : null;
    }

    private function status(mixed $status): string
    {
        return is_string($status) && $status !== '' ? $status : 'withheld';
    }

    private function actualStatus(mixed $actuals, string $sourceStatus): string
    {
        return is_array($actuals) ? $sourceStatus : 'withheld';
    }

    private function publishStatus(mixed $publishable): string
    {
        return $publishable === true ? 'publishable' : 'withheld';
    }

    private function valueStatus(mixed $value, string $presentStatus): string
    {
        return $value === null ? 'withheld' : $presentStatus;
    }

    /** @return list<int|string> */
    private function row(mixed ...$cells): array
    {
        return array_map(fn (mixed $cell): int|string => $this->safeCell($cell), $cells);
    }

    private function safeCell(mixed $value): int|string
    {
        if ($value === null) {
            return self::WITHHELD_VALUE;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_int($value)) {
            return $value;
        }
        if (! is_string($value)) {
            throw new FinancialReadModelValidationException(
                'Financial CSV cells must be strings, integers, booleans, or null.',
            );
        }

        if (
            preg_match('/^[\x00-\x20]*[=+\-@]/', $value) === 1
            || preg_match('/[\x00-\x1F\x7F]/', $value) === 1
        ) {
            return "'".$value;
        }

        return $value;
    }
}
