<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Financial\DTO;

use DateTimeInterface;
use HiEvents\DataTransferObjects\BaseDataObject;

class GetFinancialReportDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $eventId,
        public readonly string $universityId,
        public readonly string $cycleId,
        public readonly DateTimeInterface $cutoffAt,
        public readonly DateTimeInterface $generatedAt,
        public readonly bool $includeReconciliation = false,
    ) {}
}
