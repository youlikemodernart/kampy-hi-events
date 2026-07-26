<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Financial\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

class FinancialReportSourceBindingDTO extends BaseDataObject
{
    public function __construct(
        public readonly string $planSourceNamespace,
        public readonly string $ticketSourceNamespace,
        public readonly string $settlementSourceNamespace,
        public readonly string $donationSourceNamespace,
    ) {}
}
