import {
    FinancialReport,
    FinancialReportRequest,
} from "../../../../../api/financial-report.client.ts";
import {IdParam} from "../../../../../types.ts";

export const SYNTHETIC_FINANCIAL_PREVIEW = 'synthetic';
export const SYNTHETIC_FINANCIAL_PREVIEW_CURRENCY = 'USD';
const REPORTING_TIMEZONE = 'America/Phoenix';

export const buildSyntheticFinancialReport = (
    eventId: IdParam,
    request: FinancialReportRequest,
): FinancialReport => {
    const parsedEventId = Number(eventId);
    const sourceAsOfAt = '2026-07-27T12:00:00-07:00';
    const evidence = {
        available: true,
        selection: 'client_fixture',
        source_controlled: false,
        fully_promotable: false,
        policy_publishable: false,
        policy_version_matches: false,
        status: 'synthetic_preview',
        freshness: 'synthetic',
        source_as_of_at: sourceAsOfAt,
    };

    return {
        scope: {
            event_id: Number.isInteger(parsedEventId) ? parsedEventId : null,
            university_id: request.university_id,
            cycle_id: request.cycle_id,
        },
        cutoff_at: request.cutoff_at,
        generated_at: sourceAsOfAt,
        reporting_timezone: REPORTING_TIMEZONE,
        financial_policy: {
            policy_version: 'synthetic-preview-v1',
            effective_at: sourceAsOfAt,
            reporting_timezone: REPORTING_TIMEZONE,
            source_freshness_seconds: null,
            ticket_revenue: {
                basis: 'connected_settlement_after_adjustments',
                fixed_deduction_cents: 600,
                eligible_transaction_definition: 'settled_paid_ticket',
            },
            fundraising: {
                allocation_base: 'gross_less_refunds',
                allocation_basis_points: 4000,
                processing_fees_reduce_university_revenue: false,
                processing_fee_rationale: 'synthetic_preview_assumption',
                processing_fee_confirmation: 'synthetic_preview_assumption',
            },
            adjustments: {
                timing: 'immediate',
            },
        },
        plan: {
            as_of_at: sourceAsOfAt,
            pricing_convention: 'customer_charge',
            basis_point_rounding: 'half_up_to_cent',
            ticket_quantity: 1500,
            ticket_customer_price_cents: 5500,
            per_ticket_commission_cents: 600,
            fundraising_goal_cents: 2000000,
            university_allocation_basis_points: 4000,
            donorbox_fee_basis_points: 175,
            totals: {
                planned_ticket_customer_charge_cents: 8250000,
                planned_commission_cents: 900000,
                planned_ticket_proceeds_cents: 7350000,
                planned_fundraising_goal_cents: 2000000,
                planned_university_fundraising_allocation_cents: 800000,
                planned_donorbox_fee_cents: 14000,
                planned_gross_income_cents: 8150000,
                planned_income_after_donorbox_fee_cents: 8136000,
            },
        },
        tickets: {
            status: 'synthetic_preview',
            source_publishable: false,
            policy_publishable: false,
            policy_validation_status: 'preview_only',
            eligibility_definition_matches: true,
            settlement_count_matches: true,
            recognized_revenue_cents: 471050,
            eligibility: {
                definition: 'settled_paid_ticket',
                source_grain: 'order_line',
                eligible_transaction_count: 100,
                zero_price_review_count: 3,
                unpaid_or_unsettled_count: 7,
            },
            actuals: {
                record_count: 100,
                quantity: 100,
                customer_charge_cents: 550000,
                kamp_proceeds_cents: 490000,
                application_fee_cents: 60000,
                application_fee_actual_cents: 60000,
                application_fee_estimated_cents: null,
                processor_fee_cents: 18950,
                processor_fee_actual_cents: 18950,
                processor_fee_estimated_cents: null,
                refund_cents: 0,
                payment_reversal_cents: 0,
                kamp_net_settlement_cents: 471050,
            },
            source_status: {
                status: 'synthetic_preview',
                freshness: 'synthetic',
                source_as_of_at: sourceAsOfAt,
            },
            settlement: {
                status: 'synthetic_preview',
                source_publishable: false,
                policy_publishable: false,
                actuals: {
                    record_count: 100,
                    customer_charge_cents: 550000,
                    stripe_processing_fee_cents: 18950,
                    application_fee_cents: 60000,
                    connected_net_cents: 471050,
                    refund_cents: 0,
                    application_fee_refund_cents: 0,
                    dispute_amount_cents: 0,
                    dispute_fee_cents: 0,
                    connected_settlement_after_adjustments_cents: 471050,
                    immediate_adjustment_cents: 0,
                },
                source_status: {
                    status: 'synthetic_preview',
                    freshness: 'synthetic',
                    source_as_of_at: sourceAsOfAt,
                },
            },
        },
        donations: {
            status: 'synthetic_preview',
            source_publishable: false,
            fully_promotable: false,
            policy_publishable: false,
            recognized_revenue_cents: 96000,
            allocation_base_cents: 240000,
            validation_required: true,
            gross_actuals: {
                record_count: 24,
                gross_cents: 250000,
                amount_refunded_cents: 10000,
            },
            net_actuals: {
                record_count: 24,
                gross_cents: 250000,
                amount_refunded_cents: 10000,
                platform_fee_cents: 4200,
                processor_fee_cents: 8100,
                net_cents: 227700,
            },
            source_status: {
                status: 'synthetic_preview',
                freshness: 'synthetic',
                source_as_of_at: sourceAsOfAt,
            },
        },
        variances: {
            ticket_quantity: -1400,
            ticket_proceeds_cents: -6878950,
            fundraising_gross_cents: -1760000,
        },
        current_position: {
            known_cents: 567050,
            complete: false,
            missing_or_unpublishable_sources: [
                'live_ticket_source',
                'live_stripe_settlement',
                'live_donorbox_mapping',
            ],
            components: [
                {source: 'synthetic_ticket_net', cents: 471050},
                {source: 'synthetic_fundraising_allocation', cents: 96000},
            ],
        },
        source_evidence: {
            plan: {...evidence},
            tickets: {...evidence},
            settlement: {...evidence},
            donations: {...evidence},
        },
        publishable: false,
    };
};
