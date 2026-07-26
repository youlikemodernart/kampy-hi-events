<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Financial\DTOs;

use DateTimeInterface;
use HiEvents\DataTransferObjects\BaseDataObject;

class FinancialScopeDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $scopeKey,
        public readonly int $accountId,
        public readonly int $organizerId,
        public readonly int $eventId,
        public readonly string $universityId,
        public readonly string $cycleId,
        public readonly string $timezone,
        public readonly string $currency,
        public readonly DateTimeInterface $recordedAt,
    ) {}
}
