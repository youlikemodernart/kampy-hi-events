<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Financial\DTOs;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\FinancialAppendClassification;

class FinancialSnapshotAppendPlanDTO extends BaseDataObject
{
    public function __construct(
        public readonly FinancialAppendClassification $classification,
        public readonly bool $appendSnapshot,
        public readonly bool $appendRecords,
        public readonly bool $appendPlanRevision,
        public readonly bool $appendReceipt,
        public readonly bool $promotionEligible,
        public readonly FinancialSnapshotBatchDTO $batch,
        public readonly ?FinancialPersistedReceiptDTO $receipt,
    ) {}
}
