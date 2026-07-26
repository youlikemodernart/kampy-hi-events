import {api} from "./client";
import {GenericDataResponse, IdParam} from "../types.ts";

export interface FinancialReportRequest {
    university_id: string;
    cycle_id: string;
    cutoff_at: string;
}

interface FinancialSourceStatus {
    status: string | null;
    freshness: string | null;
    source_as_of_at: string | null;
}

interface FinancialSourceEvidence {
    available: boolean | null;
    selection: string | null;
    source_controlled: boolean | null;
    fully_promotable: boolean | null;
    policy_publishable: boolean | null;
    policy_version_matches: boolean | null;
    status: string | null;
    freshness: string | null;
    source_as_of_at: string | null;
}

interface FinancialTicketActuals {
    record_count: number | null;
    quantity: number | null;
    customer_charge_cents: number | null;
    kamp_proceeds_cents: number | null;
    application_fee_cents: number | null;
    application_fee_actual_cents: number | null;
    application_fee_estimated_cents: number | null;
    processor_fee_cents: number | null;
    processor_fee_actual_cents: number | null;
    processor_fee_estimated_cents: number | null;
    refund_cents: number | null;
    payment_reversal_cents: number | null;
    kamp_net_settlement_cents: number | null;
}

interface FinancialSettlementActuals {
    record_count: number | null;
    customer_charge_cents: number | null;
    stripe_processing_fee_cents: number | null;
    application_fee_cents: number | null;
    connected_net_cents: number | null;
    refund_cents: number | null;
    application_fee_refund_cents: number | null;
    dispute_amount_cents: number | null;
    dispute_fee_cents: number | null;
    connected_settlement_after_adjustments_cents: number | null;
    immediate_adjustment_cents: number | null;
}

interface FinancialDonationActuals {
    record_count: number | null;
    gross_cents: number | null;
    amount_refunded_cents: number | null;
    platform_fee_cents: number | null;
    processor_fee_cents: number | null;
    net_cents: number | null;
}

export interface FinancialReport {
    scope: {
        event_id: number | null;
        university_id: string | null;
        cycle_id: string | null;
    };
    cutoff_at: string;
    generated_at: string;
    reporting_timezone: string;
    financial_policy: {
        policy_version: string | null;
        effective_at: string | null;
        reporting_timezone: string | null;
        source_freshness_seconds: number | null;
        ticket_revenue: {
            basis: string | null;
            fixed_deduction_cents: number | null;
            eligible_transaction_definition: string | null;
        } | null;
        fundraising: {
            allocation_base: string | null;
            allocation_basis_points: number | null;
            processing_fees_reduce_university_revenue: boolean | null;
            processing_fee_rationale: string | null;
            processing_fee_confirmation: string | null;
        } | null;
        adjustments: {
            timing: string | null;
        } | null;
    };
    plan: {
        as_of_at: string | null;
        pricing_convention: string | null;
        basis_point_rounding: string | null;
        ticket_quantity: number | null;
        ticket_customer_price_cents: number | null;
        per_ticket_commission_cents: number | null;
        fundraising_goal_cents: number | null;
        university_allocation_basis_points: number | null;
        donorbox_fee_basis_points: number | null;
        totals: {
            planned_ticket_customer_charge_cents: number | null;
            planned_commission_cents: number | null;
            planned_ticket_proceeds_cents: number | null;
            planned_fundraising_goal_cents: number | null;
            planned_university_fundraising_allocation_cents: number | null;
            planned_donorbox_fee_cents: number | null;
            planned_gross_income_cents: number | null;
            planned_income_after_donorbox_fee_cents: number | null;
        } | null;
    };
    tickets: {
        status: string | null;
        source_publishable: boolean | null;
        policy_publishable: boolean | null;
        policy_validation_status: string | null;
        eligibility_definition_matches: boolean | null;
        settlement_count_matches: boolean | null;
        recognized_revenue_cents: number | null;
        eligibility: {
            definition: string | null;
            source_grain: string | null;
            eligible_transaction_count: number | null;
            zero_price_review_count: number | null;
            unpaid_or_unsettled_count: number | null;
        } | null;
        actuals: FinancialTicketActuals | null;
        source_status: FinancialSourceStatus | null;
        settlement: {
            status: string | null;
            source_publishable: boolean | null;
            policy_publishable: boolean | null;
            actuals: FinancialSettlementActuals | null;
            source_status: FinancialSourceStatus | null;
        };
    };
    donations: {
        status: string | null;
        source_publishable: boolean | null;
        fully_promotable: boolean | null;
        policy_publishable: boolean | null;
        recognized_revenue_cents: number | null;
        allocation_base_cents: number | null;
        validation_required: boolean | null;
        gross_actuals: {
            record_count: number | null;
            gross_cents: number | null;
            amount_refunded_cents: number | null;
        } | null;
        net_actuals: FinancialDonationActuals | null;
        source_status: FinancialSourceStatus | null;
    };
    variances: {
        ticket_quantity: number | null;
        ticket_proceeds_cents: number | null;
        fundraising_gross_cents: number | null;
    };
    current_position: {
        known_cents: number | null;
        complete: boolean | null;
        missing_or_unpublishable_sources: string[] | null;
        components: Array<{
            source: string | null;
            cents: number | null;
        }>;
    };
    source_evidence: {
        plan: FinancialSourceEvidence;
        tickets: FinancialSourceEvidence;
        settlement: FinancialSourceEvidence;
        donations: FinancialSourceEvidence;
    };
    publishable: boolean;
}

export const financialReportClient = {
    get: async (eventId: IdParam, request: FinancialReportRequest): Promise<FinancialReport> => {
        const response = await api.get<GenericDataResponse<FinancialReport>>(
            `events/${eventId}/financial-report`,
            {params: request},
        );

        return response.data.data;
    },

    exportCsv: async (eventId: IdParam, request: FinancialReportRequest): Promise<Blob> => {
        const response = await api.get(
            `events/${eventId}/financial-report/export`,
            {
                params: request,
                responseType: 'blob',
            },
        );

        return new Blob([response.data], {type: 'text/csv;charset=utf-8'});
    },
};
