<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Financial\DTOs;

use DateTimeInterface;
use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\FinancialSnapshotKind;
use HiEvents\DomainObjects\Enums\FinancialSourceSystem;
use HiEvents\DomainObjects\Status\FinancialReconciliationStatus;

class FinancialSnapshotDTO extends BaseDataObject
{
    /**
     * @param  array<string, bool|int|string|null|list<string>>  $summary
     */
    public function __construct(
        public readonly string $snapshotId,
        public readonly string $streamKey,
        public readonly string $sourceVersionKey,
        public readonly int $financialScopeId,
        public readonly string $scopeKey,
        public readonly string $universityId,
        public readonly string $cycleId,
        public readonly FinancialSnapshotKind $snapshotKind,
        public readonly FinancialSourceSystem $sourceSystem,
        public readonly string $sourceNamespace,
        public readonly string $adapterVersion,
        public readonly DateTimeInterface $sourceAsOfAt,
        public readonly DateTimeInterface $importedAt,
        public readonly ?string $policyVersion,
        public readonly string $contentFingerprint,
        public readonly FinancialReconciliationStatus $status,
        public readonly bool $sourcePublishable,
        public readonly bool $policyPublishable,
        public readonly int $recordCount,
        public readonly array $summary,
    ) {}
}
