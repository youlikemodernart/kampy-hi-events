<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Financial\DTOs;

use DateTimeInterface;
use HiEvents\DataTransferObjects\BaseDataObject;

class FinancialReadModelDTO extends BaseDataObject
{
    /**
     * @param  array{scopeKey: string, accountId: int, organizerId: int, eventId: int, universityId: string, cycleId: string}  $scope
     * @param  array<string, mixed>  $financialPolicy
     * @param  array<string, mixed>  $plan
     * @param  array<string, mixed>  $tickets
     * @param  array<string, mixed>  $donations
     * @param  array<string, int|null>  $variances
     * @param  array<string, mixed>  $currentPosition
     * @param  array<string, array<string, bool|string|null>>  $sourceEvidence
     * @param  array<string, mixed>|null  $reconciliation
     */
    public function __construct(
        public readonly array $scope,
        public readonly DateTimeInterface $cutoffAt,
        public readonly DateTimeInterface $generatedAt,
        public readonly string $reportingTimezone,
        public readonly array $financialPolicy,
        public readonly array $plan,
        public readonly array $tickets,
        public readonly array $donations,
        public readonly array $variances,
        public readonly array $currentPosition,
        public readonly array $sourceEvidence,
        public readonly bool $publishable,
        public readonly ?array $reconciliation,
    ) {}
}
