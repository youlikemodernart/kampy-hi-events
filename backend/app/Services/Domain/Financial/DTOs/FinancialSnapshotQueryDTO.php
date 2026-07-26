<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Financial\DTOs;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\FinancialSnapshotKind;

class FinancialSnapshotQueryDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $scopeKey,
        public readonly int $accountId,
        public readonly int $organizerId,
        public readonly int $eventId,
        public readonly string $universityId,
        public readonly string $cycleId,
        public readonly FinancialSnapshotKind $snapshotKind,
        public readonly string $sourceNamespace,
    ) {}
}
