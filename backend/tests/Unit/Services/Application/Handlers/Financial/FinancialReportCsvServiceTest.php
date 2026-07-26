<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Application\Handlers\Financial;

use HiEvents\Exceptions\FinancialReadModelValidationException;
use HiEvents\Services\Application\Handlers\Financial\DTO\FinancialReportResponseDTO;
use HiEvents\Services\Application\Handlers\Financial\FinancialReportCsvService;
use PHPUnit\Framework\TestCase;

class FinancialReportCsvServiceTest extends TestCase
{
    private FinancialReportCsvService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FinancialReportCsvService;
    }

    public function test_columns_and_metric_order_are_exact_and_stable(): void
    {
        $rows = $this->service->rows($this->response());

        $this->assertSame(['section', 'metric', 'value', 'status'], $this->service->headers());
        $this->assertSame([
            'scope.event_id',
            'scope.university_id',
            'scope.cycle_id',
            'report.cutoff_at',
            'report.generated_at',
            'report.reporting_timezone',
            'policy.version',
            'policy.ticket_revenue_basis',
            'policy.ticket_fixed_deduction_cents',
            'policy.eligible_transaction_definition',
            'policy.fundraising_allocation_basis_points',
            'policy.processing_fees_reduce_university_revenue',
            'policy.processing_fee_confirmation',
            'policy.adjustment_timing',
            'plan.as_of_at',
            'plan.ticket_quantity',
            'plan.ticket_proceeds_cents',
            'plan.fundraising_goal_cents',
            'plan.gross_income_cents',
            'tickets.status',
            'tickets.eligible_transaction_count',
            'tickets.policy_validation_status',
            'tickets.recognized_revenue_cents',
            'tickets.quantity',
            'tickets.customer_charge_cents',
            'tickets.application_fee_cents',
            'tickets.processor_fee_cents',
            'tickets.refund_cents',
            'tickets.kamp_net_settlement_cents',
            'stripe_settlement.status',
            'stripe_settlement.customer_charge_cents',
            'stripe_settlement.processing_fee_cents',
            'stripe_settlement.application_fee_cents',
            'stripe_settlement.connected_net_cents',
            'stripe_settlement.refund_cents',
            'stripe_settlement.application_fee_refund_cents',
            'stripe_settlement.dispute_amount_cents',
            'stripe_settlement.dispute_fee_cents',
            'stripe_settlement.immediate_adjustment_cents',
            'stripe_settlement.connected_after_adjustments_cents',
            'donations.status',
            'donations.gross_cents',
            'donations.refund_cents',
            'donations.recognized_revenue_cents',
            'variance.ticket_quantity',
            'variance.ticket_proceeds_cents',
            'variance.fundraising_gross_cents',
            'position.known_cents',
            'position.complete',
            'position.publishable',
        ], array_map(
            static fn (array $row): string => $row[0].'.'.$row[1],
            $rows,
        ));
    }

    public function test_withheld_values_are_explicit_and_negative_integer_values_remain_numeric(): void
    {
        $rows = $this->service->rows($this->response());

        $recognizedTicket = $this->findRow($rows, 'tickets', 'recognized_revenue_cents');
        $ticketVariance = $this->findRow($rows, 'variance', 'ticket_proceeds_cents');

        $this->assertSame(FinancialReportCsvService::WITHHELD_VALUE, $recognizedTicket[2]);
        $this->assertSame('withheld', $recognizedTicket[3]);
        $this->assertSame(-600, $ticketVariance[2]);
        $this->assertSame('calculated', $ticketVariance[3]);
    }

    public function test_every_string_cell_is_spreadsheet_injection_safe(): void
    {
        $rows = $this->service->rows($this->response(unsafeStrings: true));

        $this->assertSame("'=HYPERLINK(\"https://example.test\")", $this->findRow($rows, 'scope', 'university_id')[2]);
        $this->assertSame("'\t@SUM(1+1)", $this->findRow($rows, 'policy', 'version')[2]);

        foreach ([$this->service->headers(), ...$rows] as $row) {
            foreach ($row as $cell) {
                if (! is_string($cell)) {
                    continue;
                }

                $this->assertDoesNotMatchRegularExpression('/^[\x00-\x20]*[=+\-@]/', $cell);
            }
        }
    }

    public function test_reconciliation_details_are_rejected(): void
    {
        $this->expectException(FinancialReadModelValidationException::class);
        $this->service->rows($this->response(['ticket_receipt' => []]));
    }

    /** @param list<list<int|string>> $rows */
    private function findRow(array $rows, string $section, string $metric): array
    {
        foreach ($rows as $row) {
            if ($row[0] === $section && $row[1] === $metric) {
                return $row;
            }
        }

        $this->fail("Missing CSV row {$section}.{$metric}");
    }

    private function response(
        ?array $reconciliation = null,
        bool $unsafeStrings = false,
    ): FinancialReportResponseDTO {
        return new FinancialReportResponseDTO(
            scope: [
                'event_id' => 31,
                'university_id' => $unsafeStrings
                    ? '=HYPERLINK("https://example.test")'
                    : 'gcu',
                'cycle_id' => '2026-fall',
            ],
            cutoffAt: '2026-07-25T23:59:59-07:00',
            generatedAt: '2026-07-26T00:05:00+00:00',
            reportingTimezone: 'America/Phoenix',
            financialPolicy: [
                'policy_version' => $unsafeStrings ? "\t@SUM(1+1)" : '2026-07-25.2',
                'ticket_revenue' => [
                    'basis' => 'stripe_net_minus_fixed_deduction',
                    'fixed_deduction_cents' => 600,
                    'eligible_transaction_definition' => 'one charged attendee row',
                ],
                'fundraising' => [
                    'allocation_basis_points' => 4000,
                    'processing_fees_reduce_university_revenue' => false,
                    'processing_fee_confirmation' => 'unconfirmed',
                ],
                'adjustments' => ['timing' => 'immediate'],
            ],
            plan: [
                'as_of_at' => '2026-07-25T12:00:00-07:00',
                'ticket_quantity' => 1,
                'totals' => [
                    'planned_ticket_proceeds_cents' => 4900,
                    'planned_fundraising_goal_cents' => 10000,
                    'planned_gross_income_cents' => 14900,
                ],
            ],
            tickets: [
                'status' => 'pass',
                'policy_publishable' => false,
                'policy_validation_status' => 'application_fee_already_deducted',
                'recognized_revenue_cents' => null,
                'eligibility' => ['eligible_transaction_count' => 1],
                'actuals' => [
                    'quantity' => 1,
                    'customer_charge_cents' => 5695,
                    'application_fee_cents' => 600,
                    'processor_fee_cents' => 195,
                    'refund_cents' => 0,
                    'kamp_net_settlement_cents' => 4900,
                ],
                'settlement' => [
                    'status' => 'pass',
                    'actuals' => [
                        'customer_charge_cents' => 5695,
                        'stripe_processing_fee_cents' => 195,
                        'application_fee_cents' => 600,
                        'connected_net_cents' => 4900,
                        'refund_cents' => 0,
                        'application_fee_refund_cents' => 0,
                        'dispute_amount_cents' => 0,
                        'dispute_fee_cents' => 0,
                        'immediate_adjustment_cents' => 0,
                        'connected_settlement_after_adjustments_cents' => 4900,
                    ],
                ],
            ],
            donations: [
                'status' => 'review_required',
                'policy_publishable' => false,
                'recognized_revenue_cents' => null,
                'gross_actuals' => ['gross_cents' => 10000, 'amount_refunded_cents' => 0],
            ],
            variances: [
                'ticket_quantity' => 0,
                'ticket_proceeds_cents' => -600,
                'fundraising_gross_cents' => 0,
            ],
            currentPosition: ['known_cents' => 0, 'complete' => false],
            sourceEvidence: [],
            publishable: false,
            reconciliation: $reconciliation,
        );
    }
}
