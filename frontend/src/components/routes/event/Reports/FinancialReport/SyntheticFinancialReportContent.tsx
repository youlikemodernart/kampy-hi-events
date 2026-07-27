/* eslint-disable lingui/no-unlocalized-strings -- private synthetic preview copy is intentionally English-only */
import {
    Accordion,
    Badge,
    Box,
    Divider,
    Group,
    Paper,
    Progress,
    SimpleGrid,
    Stack,
    Text,
    Title,
} from "@mantine/core";
import {ReactNode} from "react";
import {FinancialReport} from "../../../../../api/financial-report.client.ts";
import {SYNTHETIC_FINANCIAL_PREVIEW_CURRENCY} from "./syntheticPreview.ts";

interface MetricRow {
    label: string;
    value: ReactNode;
}

interface ComparisonCardProps {
    title: string;
    description: string;
    plan: string;
    result: string;
    difference: string;
    progress: number | null;
}

const locale = (): string => typeof navigator === 'undefined' ? 'en-US' : navigator.language;

const formatInteger = (value: number | null | undefined): string => {
    if (value === null || value === undefined) {
        return 'Unavailable';
    }

    return new Intl.NumberFormat(locale()).format(value);
};

const formatMoney = (value: number | null | undefined): string => {
    if (value === null || value === undefined) {
        return 'Unavailable';
    }

    return new Intl.NumberFormat(locale(), {
        style: 'currency',
        currency: SYNTHETIC_FINANCIAL_PREVIEW_CURRENCY,
        minimumFractionDigits: 2,
        maximumFractionDigits: 2,
    }).format(value / 100);
};

const formatDeduction = (value: number | null | undefined): string => {
    if (value === null || value === undefined) {
        return 'Unavailable';
    }

    return value === 0 ? formatMoney(0) : `-${formatMoney(Math.abs(value))}`;
};

const formatDateTime = (value: string | null | undefined, timezone: string): string => {
    if (!value) {
        return 'Date unavailable';
    }

    const parsed = new Date(value);
    if (Number.isNaN(parsed.getTime())) {
        return value;
    }

    return new Intl.DateTimeFormat(locale(), {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        timeZone: timezone,
        timeZoneName: 'short',
    }).format(parsed);
};

const percentage = (
    value: number | null | undefined,
    plan: number | null | undefined,
): number | null => {
    if (value === null || value === undefined || plan === null || plan === undefined || plan <= 0) {
        return null;
    }

    return Math.max(0, Math.min(100, value / plan * 100));
};

const formatPercentage = (value: number | null): string => value === null
    ? 'Progress unavailable'
    : `${new Intl.NumberFormat(locale(), {maximumFractionDigits: 1}).format(value)}%`;

const formatProgressNote = (value: number | null, target: 'plan' | 'goal'): string => value === null
    ? 'Progress unavailable'
    : `${formatPercentage(value)} of ${target}`;

const formatMoneyDifference = (value: number | null | undefined, noun: string): string => {
    if (value === null || value === undefined) {
        return 'Difference unavailable';
    }

    if (value === 0) {
        return `On ${noun}`;
    }

    return value < 0
        ? `${formatMoney(Math.abs(value))} below ${noun}`
        : `${formatMoney(value)} above ${noun}`;
};

const formatCountDifference = (value: number | null | undefined): string => {
    if (value === null || value === undefined) {
        return 'Difference unavailable';
    }

    if (value === 0) {
        return 'On plan';
    }

    return value < 0
        ? `${formatInteger(Math.abs(value))} fewer than plan`
        : `${formatInteger(value)} more than plan`;
};

const humanSourceLabel = (source: string): string => ({
    plan: 'Event plan',
    tickets: 'Live ticket data',
    settlement: 'Live Stripe settlement',
    donations: 'Live DonorBox mapping',
    live_ticket_source: 'Live ticket data',
    live_stripe_settlement: 'Live Stripe settlement',
    live_donorbox_mapping: 'Live DonorBox mapping',
    synthetic_ticket_net: 'Ticket settlement',
    synthetic_fundraising_allocation: 'Fundraising allocation',
}[source] ?? source.replaceAll('_', ' '));

const MetricList = ({rows}: { rows: MetricRow[] }) => (
    <Box component="dl" m={0}>
        {rows.map((row, index) => (
            <Group
                component="div"
                key={row.label}
                justify="space-between"
                align="flex-start"
                wrap="wrap"
                gap="xs"
                py="xs"
                style={index < rows.length - 1
                    ? {borderBottom: '1px solid var(--mantine-color-gray-2)'}
                    : undefined}
            >
                <Text component="dt" size="sm" fw={600}>{row.label}</Text>
                <Text
                    component="dd"
                    size="sm"
                    m={0}
                    ta="right"
                    style={{fontVariantNumeric: 'tabular-nums'}}
                >
                    {row.value}
                </Text>
            </Group>
        ))}
    </Box>
);

const SnapshotMetric = ({label, value, note}: { label: string; value: string; note: string }) => (
    <Box>
        <Text size="xs" c="dimmed" tt="uppercase" fw={700} lts="0.04em">{label}</Text>
        <Text size="lg" fw={700} mt={2} style={{fontVariantNumeric: 'tabular-nums'}}>{value}</Text>
        <Text size="sm" c="dimmed" mt={2}>{note}</Text>
    </Box>
);

const ComparisonCard = ({
    title,
    description,
    plan,
    result,
    difference,
    progress,
}: ComparisonCardProps) => (
    <Paper component="section" aria-label={title} withBorder p="lg" radius="md">
        <Title order={3} size="h5">{title}</Title>
        <Text size="sm" c="dimmed" mt={2}>{description}</Text>
        <Group justify="space-between" mt="md" mb={6} gap="xs">
            <Text size="sm" fw={600}>Fixture progress</Text>
            <Text size="sm" fw={700} style={{fontVariantNumeric: 'tabular-nums'}}>
                {formatPercentage(progress)}
            </Text>
        </Group>
        <Progress value={progress ?? 0} color="violet" size="md" radius="xl" aria-label={`${title} ${formatPercentage(progress)}`}/>
        <SimpleGrid cols={{base: 1, sm: 3}} spacing="md" mt="lg">
            <SnapshotMetric label="Plan" value={plan} note="Target"/>
            <SnapshotMetric label="Fixture result" value={result} note="Invented value"/>
            <SnapshotMetric label="Difference" value={difference} note="Fixture versus plan"/>
        </SimpleGrid>
    </Paper>
);

const SectionHeader = ({title}: { title: string }) => (
    <Group justify="space-between" align="center" wrap="wrap" gap="xs" mb="md">
        <Title order={2} size="h4">{title}</Title>
        <Badge color="violet" variant="light">Synthetic · USD</Badge>
    </Group>
);

const SyntheticFinancialReportContent = ({report}: { report: FinancialReport }) => {
    const ticketPlan = report.plan.ticket_quantity;
    const ticketResult = report.tickets.actuals?.quantity;
    const ticketProceedsPlan = report.plan.totals?.planned_ticket_proceeds_cents;
    const ticketProceedsResult = report.tickets.recognized_revenue_cents;
    const fundraisingPlan = report.plan.totals?.planned_fundraising_goal_cents;
    const fundraisingResult = report.donations.allocation_base_cents;
    const missingSources = report.current_position.missing_or_unpublishable_sources ?? [];
    const evidenceRows = Object.entries(report.source_evidence);
    const plannedKampyIncome = report.plan.totals?.planned_gross_income_cents;
    const currentPlanProgress = percentage(report.current_position.known_cents, plannedKampyIncome);
    const ticketQuantityProgress = percentage(ticketResult, ticketPlan);
    const fundraisingProgress = percentage(fundraisingResult, fundraisingPlan);
    const ticketFixedDeduction = report.financial_policy.ticket_revenue?.fixed_deduction_cents;
    const fundraisingRate = report.financial_policy.fundraising?.allocation_basis_points === null
        || report.financial_policy.fundraising?.allocation_basis_points === undefined
        ? null
        : report.financial_policy.fundraising.allocation_basis_points / 100;

    return (
        <Stack gap="lg">
            <Paper component="section" withBorder p={{base: 'lg', sm: 'xl'}} radius="md">
                <SectionHeader title="Fixture financial snapshot"/>
                <Text size="sm" c="dimmed">Fixture amount attributable to Kampy</Text>
                <Text
                    component="p"
                    fz="2rem"
                    lh={1.2}
                    fw={700}
                    mt={4}
                    mb={0}
                    style={{fontVariantNumeric: 'tabular-nums'}}
                >
                    {formatMoney(report.current_position.known_cents)}
                </Text>
                <Text size="sm" mt="xs">
                    Through {formatDateTime(report.cutoff_at, report.reporting_timezone)}
                </Text>
                <Text size="sm" c="dimmed" mt={4}>
                    {formatMoney(report.tickets.recognized_revenue_cents)} ticket settlement + {formatMoney(report.donations.recognized_revenue_cents)} fundraising allocation
                </Text>

                <SimpleGrid cols={{base: 1, sm: 3}} spacing="lg" mt="xl">
                    <SnapshotMetric
                        label="Planned Kampy income"
                        value={formatMoney(plannedKampyIncome)}
                        note={formatProgressNote(currentPlanProgress, 'plan')}
                    />
                    <SnapshotMetric
                        label="Ticket progress"
                        value={`${formatInteger(ticketResult)} of ${formatInteger(ticketPlan)}`}
                        note={formatProgressNote(ticketQuantityProgress, 'plan')}
                    />
                    <SnapshotMetric
                        label="Fundraising progress"
                        value={`${formatMoney(fundraisingResult)} of ${formatMoney(fundraisingPlan)}`}
                        note={formatProgressNote(fundraisingProgress, 'goal')}
                    />
                </SimpleGrid>

                <Divider my="lg"/>
                <Text fw={700}>Incomplete fixture. Real event results are unavailable.</Text>
                <Text size="sm" c="dimmed" mt={4}>
                    {missingSources.length > 0
                        ? `${missingSources.map(humanSourceLabel).join(', ')} are not connected.`
                        : 'No live sources are connected.'}
                </Text>
            </Paper>

            <Paper component="section" withBorder p={{base: 'lg', sm: 'xl'}} radius="md">
                <SectionHeader title="Plan and fixture progress"/>
                <Text size="sm" c="dimmed" mb="lg">
                    Each comparison keeps the plan, invented result, and difference together. No overall variance is inferred.
                </Text>
                <Stack gap="md">
                    <ComparisonCard
                        title="Ticket sales"
                        description="Settled ticket quantity"
                        plan={formatInteger(ticketPlan)}
                        result={formatInteger(ticketResult)}
                        difference={formatCountDifference(report.variances.ticket_quantity)}
                        progress={ticketQuantityProgress}
                    />
                    <ComparisonCard
                        title="Ticket proceeds"
                        description="Kampy settlement after deductions and Stripe fees"
                        plan={formatMoney(ticketProceedsPlan)}
                        result={formatMoney(ticketProceedsResult)}
                        difference={formatMoneyDifference(report.variances.ticket_proceeds_cents, 'plan')}
                        progress={percentage(ticketProceedsResult, ticketProceedsPlan)}
                    />
                    <ComparisonCard
                        title="Fundraising"
                        description="Donations after refunds compared with the fundraising goal"
                        plan={formatMoney(fundraisingPlan)}
                        result={formatMoney(fundraisingResult)}
                        difference={formatMoneyDifference(report.variances.fundraising_gross_cents, 'goal')}
                        progress={fundraisingProgress}
                    />
                </Stack>
            </Paper>

            <Paper component="section" withBorder p={{base: 'lg', sm: 'xl'}} radius="md">
                <SectionHeader title="Fixture results"/>
                <SimpleGrid cols={{base: 1, md: 2}} spacing="lg">
                    <Paper withBorder p="lg" radius="md">
                        <Title order={3} size="h5">Ticket result</Title>
                        <Text size="sm" c="dimmed" mt={2} mb="sm">
                            One view of customer charges, deductions, fees, and Kampy settlement.
                        </Text>
                        <MetricList rows={[
                            {label: 'Settled tickets', value: formatInteger(report.tickets.actuals?.quantity)},
                            {label: 'Customer charges', value: formatMoney(report.tickets.actuals?.customer_charge_cents)},
                            {label: 'Fixed ticket deductions', value: formatDeduction(report.tickets.actuals?.application_fee_cents)},
                            {label: 'Stripe processing fees', value: formatDeduction(report.tickets.settlement.actuals?.stripe_processing_fee_cents)},
                            {label: 'Kampy settlement', value: formatMoney(report.tickets.settlement.actuals?.connected_settlement_after_adjustments_cents)},
                        ]}/>
                    </Paper>
                    <Paper withBorder p="lg" radius="md">
                        <Title order={3} size="h5">Fundraising result</Title>
                        <Text size="sm" c="dimmed" mt={2} mb="sm">
                            The Kampy allocation is calculated from donations after refunds.
                        </Text>
                        <MetricList rows={[
                            {label: 'Gross donations', value: formatMoney(report.donations.gross_actuals?.gross_cents)},
                            {label: 'Refunds', value: formatDeduction(report.donations.gross_actuals?.amount_refunded_cents)},
                            {label: 'Donations after refunds', value: formatMoney(report.donations.allocation_base_cents)},
                            {label: 'Kampy allocation', value: formatMoney(report.donations.recognized_revenue_cents)},
                        ]}/>
                    </Paper>
                </SimpleGrid>
            </Paper>

            <Accordion multiple variant="contained" radius="md">
                <Accordion.Item value="calculation-policy">
                    <Accordion.Control>
                        <Text fw={700}>How this fixture is calculated</Text>
                        <Text size="sm" c="dimmed" mt={2}>
                            Human summary first; technical policy details are available on demand.
                        </Text>
                    </Accordion.Control>
                    <Accordion.Panel>
                        <Text size="sm" mb="md">
                            Ticket proceeds subtract a fixed deduction per settled ticket and Stripe processing fees. Fundraising allocates {fundraisingRate === null ? 'an unavailable percentage' : `${formatInteger(fundraisingRate)}%`} of donations after refunds to Kampy.
                        </Text>
                        <MetricList rows={[
                            {label: 'Fixed deduction per ticket', value: formatMoney(ticketFixedDeduction)},
                            {label: 'Fundraising allocation', value: fundraisingRate === null ? 'Unavailable' : `${formatInteger(fundraisingRate)}%`},
                            {label: 'Adjustment timing', value: 'Immediate'},
                            {label: 'Policy status', value: 'Fixture only, cannot publish'},
                        ]}/>
                    </Accordion.Panel>
                </Accordion.Item>
                <Accordion.Item value="source-status">
                    <Accordion.Control>
                        <Text fw={700}>Synthetic calculation and source details</Text>
                        <Text size="sm" c="dimmed" mt={2}>
                            Invented fixtures have no live freshness or publication status.
                        </Text>
                    </Accordion.Control>
                    <Accordion.Panel>
                        <SimpleGrid cols={{base: 1, sm: 2}} spacing="md">
                            {evidenceRows.map(([source, evidence]) => (
                                <Paper key={source} withBorder p="md" radius="md">
                                    <Text fw={700}>{humanSourceLabel(source)}</Text>
                                    <MetricList rows={[
                                        {label: 'Status', value: 'Invented fixture'},
                                        {label: 'Freshness', value: 'No live freshness'},
                                        {label: 'Publication', value: 'Fixture only, cannot publish'},
                                        {label: 'Fixture as of', value: formatDateTime(evidence.source_as_of_at, report.reporting_timezone)},
                                    ]}/>
                                </Paper>
                            ))}
                        </SimpleGrid>
                    </Accordion.Panel>
                </Accordion.Item>
            </Accordion>
        </Stack>
    );
};

export default SyntheticFinancialReportContent;
