<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Application\Handlers\Financial;

use DateTimeImmutable;
use HiEvents\DomainObjects\Enums\FinancialAppendClassification;
use HiEvents\DomainObjects\Status\FinancialFreshness;
use HiEvents\DomainObjects\Status\FinancialReconciliationStatus;
use HiEvents\Resources\Financial\FinancialReportResource;
use HiEvents\Services\Application\Handlers\Financial\DTO\FinancialReportResponseDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialPersistedReceiptDTO;
use HiEvents\Services\Domain\Financial\DTOs\FinancialReadModelDTO;
use Tests\TestCase;

class FinancialReportResponseDTOTest extends TestCase
{
    public function test_ordinary_response_is_allowlisted_and_omits_reconciliation(): void
    {
        $response = FinancialReportResponseDTO::fromReadModel($this->report(false));
        $payload = (new FinancialReportResource($response))->response()->getData(true)['data'];

        $this->assertArrayNotHasKey('reconciliation', $payload);
        $this->assertArrayNotHasKey('scope_key', $payload['scope']);
        $this->assertArrayNotHasKey('account_id', $payload['scope']);
        $this->assertArrayNotHasKey('organizer_id', $payload['scope']);
        $this->assertArrayNotHasKey('source_identity_key', $payload['plan']);
        $this->assertArrayNotHasKey('content_fingerprint', $payload['plan']);
        $this->assertArrayNotHasKey('unexpected_private_field', $payload['tickets']);
        $this->assertArrayNotHasKey('receipt_id', $payload['tickets']['source_status']);
        $this->assertArrayNotHasKey('snapshot_id', $payload['source_evidence']['tickets']);
        $this->assertNull($payload['tickets']['recognized_revenue_cents']);
        $this->assertNull($payload['donations']['net_actuals']);
    }

    public function test_authorized_reconciliation_response_serializes_minimized_receipts(): void
    {
        $response = FinancialReportResponseDTO::fromReadModel($this->report(true));
        $payload = (new FinancialReportResource($response))->response()->getData(true)['data'];

        $this->assertSame(
            'source-receipt',
            $payload['reconciliation']['ticket_receipt']['source_receipt_id'],
        );
        $this->assertSame(
            'new_snapshot',
            $payload['reconciliation']['ticket_receipt']['append_classification'],
        );
        $this->assertSame(
            4300,
            $payload['reconciliation']['policy_validation']['fundraising_recognition']['candidate_revenue_cents'],
        );
    }

    private function report(bool $includeReconciliation): FinancialReadModelDTO
    {
        $sourceStatus = [
            'receiptId' => 'internal-receipt',
            'status' => 'pass',
            'freshness' => 'current',
            'sourceAsOfAt' => '2026-07-25T23:00:00-07:00',
            'excludedCount' => 0,
            'conflictCount' => 0,
            'discrepancyCount' => 0,
        ];
        $evidence = [
            'available' => true,
            'selection' => 'latest_promotable',
            'sourceControlled' => true,
            'fullyPromotable' => true,
            'policyPublishable' => true,
            'policyVersionMatches' => true,
            'status' => 'pass',
            'freshness' => 'current',
            'snapshotId' => 'internal-snapshot',
            'sourceAsOfAt' => '2026-07-25T23:00:00-07:00',
        ];
        $ticketActuals = [
            'recordCount' => 1,
            'quantity' => 1,
            'customerChargeCents' => 5695,
            'kampProceedsCents' => 4900,
            'applicationFeeCents' => 600,
            'applicationFeeActualCents' => 600,
            'applicationFeeEstimatedCents' => 0,
            'processorFeeCents' => 195,
            'processorFeeActualCents' => 195,
            'processorFeeEstimatedCents' => 0,
            'refundCents' => 0,
            'paymentReversalCents' => 0,
            'kampNetSettlementCents' => 4900,
        ];
        $settlementActuals = [
            'recordCount' => 1,
            'customerChargeCents' => 5695,
            'stripeProcessingFeeCents' => 195,
            'applicationFeeCents' => 600,
            'connectedNetCents' => 4900,
            'refundCents' => 0,
            'applicationFeeRefundCents' => 0,
            'disputeAmountCents' => 0,
            'disputeFeeCents' => 0,
            'connectedSettlementAfterAdjustmentsCents' => 4900,
            'immediateAdjustmentCents' => 0,
        ];

        return new FinancialReadModelDTO(
            scope: [
                'scopeKey' => str_repeat('a', 64),
                'accountId' => 7,
                'organizerId' => 19,
                'eventId' => 31,
                'universityId' => 'gcu',
                'cycleId' => '2026-fall',
            ],
            cutoffAt: new DateTimeImmutable('2026-07-25T23:59:59-07:00'),
            generatedAt: new DateTimeImmutable('2026-07-26T00:05:00-07:00'),
            reportingTimezone: 'America/Phoenix',
            financialPolicy: [
                'policyVersion' => '2026-07-25.2',
                'effectiveAt' => '2026-07-25T12:00:00-07:00',
                'reportingTimezone' => 'America/Phoenix',
                'sourceFreshnessSeconds' => 86400,
                'ticketRevenue' => [],
                'fundraising' => [],
                'adjustments' => [],
            ],
            plan: [
                'asOfAt' => '2026-07-25T12:00:00-07:00',
                'sourceIdentityKey' => 'internal-source',
                'contentFingerprint' => str_repeat('b', 64),
                'totals' => [],
            ],
            tickets: [
                'status' => 'pass',
                'sourcePublishable' => true,
                'policyPublishable' => false,
                'policyValidationStatus' => 'application_fee_already_deducted',
                'eligibility' => null,
                'eligibilityDefinitionMatches' => true,
                'settlementCountMatches' => true,
                'recognizedRevenueCents' => null,
                'actuals' => $ticketActuals,
                'sourceStatus' => $sourceStatus,
                'unexpected_private_field' => 'must-not-serialize',
                'settlement' => [
                    'status' => 'pass',
                    'sourcePublishable' => true,
                    'policyPublishable' => false,
                    'actuals' => $settlementActuals,
                    'sourceStatus' => $sourceStatus,
                ],
            ],
            donations: [
                'status' => 'review_required',
                'sourcePublishable' => true,
                'fullyPromotable' => false,
                'policyPublishable' => false,
                'recognizedRevenueCents' => null,
                'allocationBaseCents' => 10000,
                'validationRequired' => true,
                'grossActuals' => ['recordCount' => 1, 'grossCents' => 10000, 'amountRefundedCents' => 0],
                'netActuals' => null,
                'sourceStatus' => $sourceStatus,
            ],
            variances: ['ticketQuantity' => 0, 'ticketProceedsCents' => null, 'fundraisingGrossCents' => 0],
            currentPosition: [
                'knownCents' => 0,
                'components' => [],
                'complete' => false,
                'missingOrUnpublishableSources' => ['tickets', 'donations'],
            ],
            sourceEvidence: [
                'plan' => $evidence,
                'tickets' => $evidence,
                'settlement' => $evidence,
                'donations' => $evidence,
            ],
            publishable: false,
            reconciliation: $includeReconciliation ? [
                'ticketReceipt' => $this->receipt(),
                'ticketSettlementReceipt' => null,
                'donationReceipt' => null,
                'ticketObservedActuals' => $ticketActuals,
                'ticketSettlementObservedActuals' => $settlementActuals,
                'donationObservedActuals' => null,
                'policyValidation' => [
                    'ticketNetSemantics' => null,
                    'fundraisingProcessingFeeConfirmation' => 'unconfirmed',
                    'fundraisingRecognition' => [
                        'allocationBaseCents' => 10000,
                        'candidateRevenueCents' => 4300,
                        'validationRequired' => true,
                        'policyPublishable' => false,
                    ],
                ],
            ] : null,
        );
    }

    private function receipt(): FinancialPersistedReceiptDTO
    {
        $time = new DateTimeImmutable('2026-07-25T23:00:00-07:00');

        return new FinancialPersistedReceiptDTO(
            persistenceReceiptId: str_repeat('c', 64),
            sourceReceiptId: 'source-receipt',
            snapshotId: str_repeat('d', 64),
            appendClassification: FinancialAppendClassification::NEW_SNAPSHOT,
            status: FinancialReconciliationStatus::PASS,
            freshness: FinancialFreshness::CURRENT,
            sourcePublishable: true,
            policyPublishable: true,
            promotionEligible: true,
            sourceRecordCount: 1,
            importedRecordCount: 1,
            excludedCount: 0,
            conflictCount: 0,
            discrepancyCount: 0,
            sourceTotals: ['recordCount' => 1],
            importedTotals: ['recordCount' => 1],
            discrepancies: [],
            sourceAsOfAt: $time,
            generatedAt: $time,
            recordedAt: $time,
        );
    }
}
