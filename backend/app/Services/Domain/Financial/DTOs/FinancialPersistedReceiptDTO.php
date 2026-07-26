<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Financial\DTOs;

use DateTimeInterface;
use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\FinancialAppendClassification;
use HiEvents\DomainObjects\Status\FinancialFreshness;
use HiEvents\DomainObjects\Status\FinancialReconciliationStatus;

class FinancialPersistedReceiptDTO extends BaseDataObject
{
    /**
     * @param  array<string, bool|int|string|null|list<string>>  $sourceTotals
     * @param  array<string, bool|int|string|null|list<string>>  $importedTotals
     * @param  list<array{field: string, sourceValue: int, importedValue: int, delta: int}>  $discrepancies
     */
    public function __construct(
        public readonly string $persistenceReceiptId,
        public readonly string $sourceReceiptId,
        public readonly string $snapshotId,
        public readonly FinancialAppendClassification $appendClassification,
        public readonly FinancialReconciliationStatus $status,
        public readonly FinancialFreshness $freshness,
        public readonly bool $sourcePublishable,
        public readonly bool $policyPublishable,
        public readonly bool $promotionEligible,
        public readonly int $sourceRecordCount,
        public readonly int $importedRecordCount,
        public readonly int $excludedCount,
        public readonly int $conflictCount,
        public readonly int $discrepancyCount,
        public readonly array $sourceTotals,
        public readonly array $importedTotals,
        public readonly array $discrepancies,
        public readonly DateTimeInterface $sourceAsOfAt,
        public readonly DateTimeInterface $generatedAt,
        public readonly DateTimeInterface $recordedAt,
    ) {}
}
