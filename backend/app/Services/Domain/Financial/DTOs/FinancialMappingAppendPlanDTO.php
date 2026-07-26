<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Financial\DTOs;

use HiEvents\DataTransferObjects\BaseDataObject;
use HiEvents\DomainObjects\Enums\FinancialAppendClassification;

class FinancialMappingAppendPlanDTO extends BaseDataObject
{
    public function __construct(
        public readonly FinancialAppendClassification $classification,
        public readonly bool $append,
        public readonly bool $promotable,
        public readonly ?string $reason = null,
        public readonly ?int $expectedRevisionNumber = null,
        public readonly ?string $expectedSupersedesMappingRevisionId = null,
    ) {}
}
