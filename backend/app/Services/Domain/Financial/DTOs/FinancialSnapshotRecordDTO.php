<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Financial\DTOs;

use DateTimeInterface;
use HiEvents\DataTransferObjects\BaseDataObject;

class FinancialSnapshotRecordDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $snapshotRecordId,
        public readonly string $snapshotId,
        public readonly int $recordOrdinal,
        public readonly string $mappingRevisionId,
        public readonly string $sourceIdentityKey,
        public readonly string $contentFingerprint,
        public readonly string $providerStatus,
        public readonly string $financialStatus,
        public readonly ?string $recognitionDisposition,
        public readonly ?string $sourceCompletenessStatus,
        public readonly ?string $sourceMethod,
        public readonly string $currency,
        public readonly int $quantity,
        public readonly ?int $grossCents,
        public readonly ?int $processorFeeCents,
        public readonly ?int $processorFeeRefundCents,
        public readonly ?string $processorFeeProvenance,
        public readonly ?int $platformFeeCents,
        public readonly ?int $platformFeeRefundCents,
        public readonly ?string $platformFeeProvenance,
        public readonly ?int $refundCents,
        public readonly ?int $paymentReversalCents,
        public readonly ?int $disputeFeeCents,
        public readonly ?int $providerNetCents,
        public readonly ?int $netSettlementCents,
        public readonly ?string $settlementSemanticStatus,
        public readonly DateTimeInterface $sourceOccurredAt,
        public readonly DateTimeInterface $sourceUpdatedAt,
    ) {}
}
