import {t} from "@lingui/macro";
import {
    Alert,
    Badge,
    Button,
    Group,
    Paper,
    SimpleGrid,
    Skeleton,
    Stack,
    Table,
    Text,
    TextInput,
    Title,
} from "@mantine/core";
import {useForm} from "@mantine/form";
import {IconDownload, IconInfoCircle} from "@tabler/icons-react";
import {isAxiosError} from "axios";
import {ReactNode, useState} from "react";
import {useParams, useSearchParams} from "react-router";
import {
    financialReportClient,
    FinancialReport,
    FinancialReportRequest,
} from "../../../../../api/financial-report.client.ts";
import {useGetFinancialReport} from "../../../../../queries/useGetFinancialReport.ts";
import {useGetMe} from "../../../../../queries/useGetMe.ts";
import {currentUserCan} from "../../../../../hooks/useIsCurrentUserAdmin.ts";
import {downloadBinary} from "../../../../../utilites/download.ts";
import {showError} from "../../../../../utilites/notifications.tsx";
import {PageBody} from "../../../../common/PageBody";
import {PageTitle} from "../../../../common/PageTitle";

const SCOPE_IDENTIFIER = /^[A-Za-z0-9][A-Za-z0-9._:-]*$/;
const RFC3339_WITH_OFFSET = /^(\d{4})-(\d{2})-(\d{2})T(\d{2}):(\d{2}):(\d{2})([+-])(\d{2}):(\d{2})$/;

const isLeapYear = (year: number): boolean => year % 4 === 0 && (year % 100 !== 0 || year % 400 === 0);

const isValidFinancialCutoff = (value: string): boolean => {
    const match = RFC3339_WITH_OFFSET.exec(value);
    if (!match) {
        return false;
    }

    const [, yearValue, monthValue, dayValue, hourValue, minuteValue, secondValue, , offsetHourValue, offsetMinuteValue] = match;
    const year = Number(yearValue);
    const month = Number(monthValue);
    const day = Number(dayValue);
    const hour = Number(hourValue);
    const minute = Number(minuteValue);
    const second = Number(secondValue);
    const offsetHour = Number(offsetHourValue);
    const offsetMinute = Number(offsetMinuteValue);
    const daysInMonth = [
        31,
        isLeapYear(year) ? 29 : 28,
        31,
        30,
        31,
        30,
        31,
        31,
        30,
        31,
        30,
        31,
    ];

    return year >= 1
        && month >= 1
        && month <= 12
        && day >= 1
        && day <= daysInMonth[month - 1]
        && hour <= 23
        && minute <= 59
        && second <= 59
        && offsetHour <= 23
        && offsetMinute <= 59;
};

interface ScopeFormValues {
    university_id: string;
    cycle_id: string;
    cutoff_at: string;
}

interface MetricRow {
    label: string;
    value: ReactNode;
}

const requestFromSearchParams = (searchParams: URLSearchParams): FinancialReportRequest | null => {
    const universityId = searchParams.get('university_id')?.trim() ?? '';
    const cycleId = searchParams.get('cycle_id')?.trim() ?? '';
    const cutoffAt = searchParams.get('cutoff_at')?.trim() ?? '';

    if (
        !universityId
        || !cycleId
        || universityId.length > 128
        || cycleId.length > 128
        || !SCOPE_IDENTIFIER.test(universityId)
        || !SCOPE_IDENTIFIER.test(cycleId)
        || !isValidFinancialCutoff(cutoffAt)
    ) {
        return null;
    }

    return {
        university_id: universityId,
        cycle_id: cycleId,
        cutoff_at: cutoffAt,
    };
};

const formatCents = (value: number | null | undefined): string => {
    if (value === null || value === undefined) {
        return t`Withheld`;
    }

    const formattedValue = formatInteger(value);
    return t`${formattedValue} cents`;
};

const formatInteger = (value: number | null | undefined): string => {
    if (value === null || value === undefined) {
        return t`Withheld`;
    }

    return new Intl.NumberFormat(
        typeof navigator === 'undefined' ? 'en-US' : navigator.language,
    ).format(value);
};

const formatText = (value: string | null | undefined): string => {
    if (!value) {
        return t`Withheld`;
    }

    return value.replaceAll('_', ' ');
};

const formatBoolean = (value: boolean | null | undefined): string => {
    if (value === null || value === undefined) {
        return t`Withheld`;
    }

    return value ? t`Yes` : t`No`;
};

const MetricTable = ({rows}: { rows: MetricRow[] }) => (
    <Table.ScrollContainer minWidth={460}>
        <Table striped highlightOnHover withRowBorders>
            <Table.Tbody>
                {rows.map((row) => (
                    <Table.Tr key={row.label}>
                        <Table.Th scope="row" style={{width: '58%'}}>{row.label}</Table.Th>
                        <Table.Td>{row.value}</Table.Td>
                    </Table.Tr>
                ))}
            </Table.Tbody>
        </Table>
    </Table.ScrollContainer>
);

const ReportSection = ({title, rows}: { title: string; rows: MetricRow[] }) => (
    <Paper component="section" withBorder p="lg" radius="md">
        <Title order={2} size="h4" mb="sm">{title}</Title>
        <MetricTable rows={rows}/>
    </Paper>
);

const queryErrorMessage = (error: unknown): { title: string; message: string } => {
    const status = isAxiosError(error) ? error.response?.status : undefined;

    if (status === 404) {
        return {
            title: t`Financial report unavailable`,
            message: t`No financial report is configured for this event and scope.`,
        };
    }

    if (status === 503) {
        return {
            title: t`Financial report data unavailable`,
            message: t`The report cannot be loaded safely right now. Please try again later.`,
        };
    }

    return {
        title: t`Unable to load financial report`,
        message: t`The report request failed. Please try again.`,
    };
};

const FinancialReportContent = ({report}: { report: FinancialReport }) => {
    const missingSources = report.current_position.missing_or_unpublishable_sources;
    const evidenceRows = Object.entries(report.source_evidence).map(([source, evidence]) => ({
        source,
        status: evidence.status,
        freshness: evidence.freshness,
        selection: evidence.selection,
        policyPublishable: evidence.policy_publishable,
        sourceAsOfAt: evidence.source_as_of_at,
    }));

    return (
        <Stack gap="lg">
            <Paper component="section" withBorder p="xl" radius="md">
                <Group justify="space-between" align="flex-start" wrap="wrap" gap="md">
                    <div>
                        <Text size="sm" c="dimmed">{t`Current known position`}</Text>
                        <Title order={2}>{formatCents(report.current_position.known_cents)}</Title>
                        <Text size="sm" mt="xs">
                            {t`Cutoff`}: {report.cutoff_at} · {report.reporting_timezone}
                        </Text>
                    </div>
                    <Badge
                        color={report.publishable ? 'green' : 'yellow'}
                        variant="light"
                        size="lg"
                    >
                        {report.publishable ? t`Publishable` : t`Review required`}
                    </Badge>
                </Group>
                <Text mt="md">
                    {report.current_position.complete === true
                        ? t`All required sources are current and publishable.`
                        : report.current_position.complete === false
                            ? t`The current position is incomplete. Unavailable sources remain withheld.`
                            : t`Report completeness is withheld.`}
                </Text>
                {report.current_position.complete !== true && (
                    <Text size="sm" c="dimmed" mt="xs">
                        {t`Missing or unpublishable sources`}: {missingSources === null
                            ? t`Withheld`
                            : missingSources.length > 0
                                ? missingSources.join(', ')
                                : t`None listed`}
                    </Text>
                )}
            </Paper>

            <SimpleGrid cols={{base: 1, lg: 2}} spacing="lg">
                <ReportSection
                    title={t`Plan`}
                    rows={[
                        {label: t`As of`, value: formatText(report.plan.as_of_at)},
                        {label: t`Planned ticket quantity`, value: formatInteger(report.plan.ticket_quantity)},
                        {label: t`Customer ticket price`, value: formatCents(report.plan.ticket_customer_price_cents)},
                        {label: t`Planned ticket proceeds`, value: formatCents(report.plan.totals?.planned_ticket_proceeds_cents)},
                        {label: t`Fundraising goal`, value: formatCents(report.plan.totals?.planned_fundraising_goal_cents)},
                        {label: t`Planned university allocation`, value: formatCents(report.plan.totals?.planned_university_fundraising_allocation_cents)},
                        {label: t`Planned gross income`, value: formatCents(report.plan.totals?.planned_gross_income_cents)},
                    ]}
                />
                <ReportSection
                    title={t`Policy`}
                    rows={[
                        {label: t`Policy version`, value: formatText(report.financial_policy.policy_version)},
                        {label: t`Effective at`, value: formatText(report.financial_policy.effective_at)},
                        {label: t`Ticket revenue basis`, value: formatText(report.financial_policy.ticket_revenue?.basis)},
                        {label: t`Ticket fixed deduction`, value: formatCents(report.financial_policy.ticket_revenue?.fixed_deduction_cents)},
                        {label: t`Fundraising allocation`, value: report.financial_policy.fundraising?.allocation_basis_points === null || report.financial_policy.fundraising?.allocation_basis_points === undefined
                            ? t`Withheld`
                            : `${formatInteger(report.financial_policy.fundraising.allocation_basis_points)} bps`},
                        {label: t`Processing fee confirmation`, value: formatText(report.financial_policy.fundraising?.processing_fee_confirmation)},
                        {label: t`Adjustment timing`, value: formatText(report.financial_policy.adjustments?.timing)},
                    ]}
                />
                <ReportSection
                    title={t`Tickets`}
                    rows={[
                        {label: t`Status`, value: formatText(report.tickets.status)},
                        {label: t`Policy validation`, value: formatText(report.tickets.policy_validation_status)},
                        {label: t`Recognized revenue`, value: formatCents(report.tickets.recognized_revenue_cents)},
                        {label: t`Eligible transactions`, value: formatInteger(report.tickets.eligibility?.eligible_transaction_count)},
                        {label: t`Ticket quantity`, value: formatInteger(report.tickets.actuals?.quantity)},
                        {label: t`Customer charges`, value: formatCents(report.tickets.actuals?.customer_charge_cents)},
                        {label: t`Kamp net settlement`, value: formatCents(report.tickets.actuals?.kamp_net_settlement_cents)},
                        {label: t`Source freshness`, value: formatText(report.tickets.source_status?.freshness)},
                    ]}
                />
                <ReportSection
                    title={t`Stripe settlement`}
                    rows={[
                        {label: t`Status`, value: formatText(report.tickets.settlement.status)},
                        {label: t`Customer charges`, value: formatCents(report.tickets.settlement.actuals?.customer_charge_cents)},
                        {label: t`Stripe processing fees`, value: formatCents(report.tickets.settlement.actuals?.stripe_processing_fee_cents)},
                        {label: t`Application fees`, value: formatCents(report.tickets.settlement.actuals?.application_fee_cents)},
                        {label: t`Connected net`, value: formatCents(report.tickets.settlement.actuals?.connected_net_cents)},
                        {label: t`Immediate adjustments`, value: formatCents(report.tickets.settlement.actuals?.immediate_adjustment_cents)},
                        {label: t`Settlement after adjustments`, value: formatCents(report.tickets.settlement.actuals?.connected_settlement_after_adjustments_cents)},
                        {label: t`Source freshness`, value: formatText(report.tickets.settlement.source_status?.freshness)},
                    ]}
                />
                <ReportSection
                    title={t`Donations`}
                    rows={[
                        {label: t`Status`, value: formatText(report.donations.status)},
                        {label: t`Recognized revenue`, value: formatCents(report.donations.recognized_revenue_cents)},
                        {label: t`Allocation base`, value: formatCents(report.donations.allocation_base_cents)},
                        {label: t`Gross raised`, value: formatCents(report.donations.gross_actuals?.gross_cents)},
                        {label: t`Refunded`, value: formatCents(report.donations.gross_actuals?.amount_refunded_cents)},
                        {label: t`Validation required`, value: formatBoolean(report.donations.validation_required)},
                        {label: t`Source freshness`, value: formatText(report.donations.source_status?.freshness)},
                    ]}
                />
                <ReportSection
                    title={t`Variances`}
                    rows={[
                        {label: t`Ticket quantity`, value: formatInteger(report.variances.ticket_quantity)},
                        {label: t`Ticket proceeds`, value: formatCents(report.variances.ticket_proceeds_cents)},
                        {label: t`Fundraising gross`, value: formatCents(report.variances.fundraising_gross_cents)},
                    ]}
                />
            </SimpleGrid>

            <Paper component="section" withBorder p="lg" radius="md">
                <Title order={2} size="h4" mb="sm">{t`Current position components`}</Title>
                {report.current_position.components.length === 0 ? (
                    <Text c="dimmed">{t`No publishable components are available.`}</Text>
                ) : (
                    <Table.ScrollContainer minWidth={460}>
                        <Table striped withRowBorders>
                            <Table.Thead>
                                <Table.Tr>
                                    <Table.Th>{t`Source`}</Table.Th>
                                    <Table.Th>{t`Amount`}</Table.Th>
                                </Table.Tr>
                            </Table.Thead>
                            <Table.Tbody>
                                {report.current_position.components.map((component, index) => (
                                    <Table.Tr key={`${component.source ?? 'withheld'}-${index}`}>
                                        <Table.Td>{formatText(component.source)}</Table.Td>
                                        <Table.Td>{formatCents(component.cents)}</Table.Td>
                                    </Table.Tr>
                                ))}
                            </Table.Tbody>
                        </Table>
                    </Table.ScrollContainer>
                )}
            </Paper>

            <Paper component="section" withBorder p="lg" radius="md">
                <Title order={2} size="h4" mb="sm">{t`Source evidence`}</Title>
                <Table.ScrollContainer minWidth={760}>
                    <Table striped withRowBorders>
                        <Table.Thead>
                            <Table.Tr>
                                <Table.Th>{t`Source`}</Table.Th>
                                <Table.Th>{t`Status`}</Table.Th>
                                <Table.Th>{t`Freshness`}</Table.Th>
                                <Table.Th>{t`Selection`}</Table.Th>
                                <Table.Th>{t`Policy publishable`}</Table.Th>
                                <Table.Th>{t`Source as of`}</Table.Th>
                            </Table.Tr>
                        </Table.Thead>
                        <Table.Tbody>
                            {evidenceRows.map((evidence) => (
                                <Table.Tr key={evidence.source}>
                                    <Table.Th scope="row">{formatText(evidence.source)}</Table.Th>
                                    <Table.Td>{formatText(evidence.status)}</Table.Td>
                                    <Table.Td>{formatText(evidence.freshness)}</Table.Td>
                                    <Table.Td>{formatText(evidence.selection)}</Table.Td>
                                    <Table.Td>{formatBoolean(evidence.policyPublishable)}</Table.Td>
                                    <Table.Td>{formatText(evidence.sourceAsOfAt)}</Table.Td>
                                </Table.Tr>
                            ))}
                        </Table.Tbody>
                    </Table>
                </Table.ScrollContainer>
            </Paper>
        </Stack>
    );
};

const FinancialReportPage = () => {
    const {eventId} = useParams<{ eventId: string }>();
    const [searchParams, setSearchParams] = useSearchParams();
    const request = requestFromSearchParams(searchParams);
    const reportQuery = useGetFinancialReport(eventId, request);
    const {data: me} = useGetMe();
    const canExport = currentUserCan(me?.permissions, 'reports.export');
    const [downloadPending, setDownloadPending] = useState(false);
    const form = useForm<ScopeFormValues>({
        initialValues: {
            university_id: searchParams.get('university_id') ?? '',
            cycle_id: searchParams.get('cycle_id') ?? '',
            cutoff_at: searchParams.get('cutoff_at') ?? '',
        },
        validate: {
            university_id: (value) => {
                const normalized = value.trim();
                return normalized.length > 0
                    && normalized.length <= 128
                    && SCOPE_IDENTIFIER.test(normalized)
                    ? null
                    : t`Enter a valid university ID.`;
            },
            cycle_id: (value) => {
                const normalized = value.trim();
                return normalized.length > 0
                    && normalized.length <= 128
                    && SCOPE_IDENTIFIER.test(normalized)
                    ? null
                    : t`Enter a valid cycle ID.`;
            },
            cutoff_at: (value) => isValidFinancialCutoff(value.trim())
                ? null
                : t`Use RFC 3339 with an explicit offset, such as 2026-08-31T23:59:59-07:00.`,
        },
    });

    const handleScopeSubmit = (values: ScopeFormValues) => {
        setSearchParams({
            university_id: values.university_id.trim(),
            cycle_id: values.cycle_id.trim(),
            cutoff_at: values.cutoff_at.trim(),
        }, {replace: true});
    };

    const handleExport = async () => {
        if (!request || !eventId || !canExport) {
            return;
        }

        setDownloadPending(true);
        try {
            const csv = await financialReportClient.exportCsv(eventId, request);
            downloadBinary(csv, 'financial-report.csv');
        } catch {
            showError(t`Financial report CSV is unavailable.`);
        } finally {
            setDownloadPending(false);
        }
    };

    const errorMessage = reportQuery.isError
        ? queryErrorMessage(reportQuery.error)
        : null;

    return (
        <PageBody>
            <Group justify="space-between" align="flex-start" wrap="wrap" gap="md" mb="lg">
                <PageTitle subheading={t`Read-only financial position with explicit source and policy withholding.`}>
                    {t`Financial Report`}
                </PageTitle>
                {request && canExport && (
                    <Button
                        leftSection={<IconDownload size={16}/>}
                        onClick={handleExport}
                        loading={downloadPending}
                    >
                        {t`Download CSV`}
                    </Button>
                )}
            </Group>

            <Paper component="section" withBorder p="lg" radius="md" mb="lg">
                <Title order={2} size="h4">{t`Report scope`}</Title>
                <Text size="sm" c="dimmed" mt={4} mb="md">
                    {t`Enter the authorized university, cycle, and cutoff. The same exact scope is used for the report and CSV export.`}
                </Text>
                <form onSubmit={form.onSubmit(handleScopeSubmit)}>
                    <SimpleGrid cols={{base: 1, md: 3}} spacing="md">
                        <TextInput
                            label={t`University ID`}
                            maxLength={128}
                            required
                            {...form.getInputProps('university_id')}
                        />
                        <TextInput
                            label={t`Cycle ID`}
                            maxLength={128}
                            required
                            {...form.getInputProps('cycle_id')}
                        />
                        <TextInput
                            label={t`Cutoff with timezone offset`}
                            description={t`RFC 3339, including seconds and offset`}
                            placeholder="2026-08-31T23:59:59-07:00"
                            required
                            {...form.getInputProps('cutoff_at')}
                        />
                    </SimpleGrid>
                    <Button type="submit" mt="md">{t`Load report`}</Button>
                </form>
            </Paper>

            {!request && (
                <Alert icon={<IconInfoCircle/>} title={t`Report scope required`} color="blue">
                    {t`No report request is made until all three scope values are valid. Access is still checked by the server.`}
                </Alert>
            )}

            {request && reportQuery.isLoading && (
                <Stack gap="lg" aria-label={t`Loading financial report`}>
                    <Skeleton height={180} radius="md"/>
                    <SimpleGrid cols={{base: 1, lg: 2}} spacing="lg">
                        <Skeleton height={320} radius="md"/>
                        <Skeleton height={320} radius="md"/>
                    </SimpleGrid>
                </Stack>
            )}

            {request && errorMessage && (
                <Alert icon={<IconInfoCircle/>} title={errorMessage.title} color="red">
                    {errorMessage.message}
                </Alert>
            )}

            {request && reportQuery.data && (
                <FinancialReportContent report={reportQuery.data}/>
            )}
        </PageBody>
    );
};

export default FinancialReportPage;
