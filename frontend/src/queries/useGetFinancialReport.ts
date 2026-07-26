import {useQuery} from "@tanstack/react-query";
import {financialReportClient, FinancialReportRequest} from "../api/financial-report.client.ts";
import {IdParam} from "../types.ts";

export const GET_FINANCIAL_REPORT_QUERY_KEY = 'getFinancialReport';

export const useGetFinancialReport = (
    eventId: IdParam,
    request: FinancialReportRequest | null,
) => useQuery({
    queryKey: [
        GET_FINANCIAL_REPORT_QUERY_KEY,
        eventId,
        request?.university_id,
        request?.cycle_id,
        request?.cutoff_at,
    ],
    queryFn: () => financialReportClient.get(eventId, request as FinancialReportRequest),
    enabled: Boolean(
        eventId
        && request?.university_id
        && request?.cycle_id
        && request?.cutoff_at,
    ),
    retry: false,
});
