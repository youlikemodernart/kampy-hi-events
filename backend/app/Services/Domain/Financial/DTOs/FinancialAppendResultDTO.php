<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Financial\DTOs;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\FinancialAppendClassification;

class FinancialAppendResultDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $operation,
        public readonly FinancialAppendClassification $classification,
        public readonly bool $appended,
        public readonly bool $appendedReceipt,
        public readonly bool $promotionEligible,
        public readonly ?string $scopeKey,
        public readonly ?string $mappingRevisionId,
        public readonly ?string $snapshotId,
        public readonly ?string $persistenceReceiptId,
        public readonly int $attempts,
        public readonly string $commitOutcome,
    ) {}
}
