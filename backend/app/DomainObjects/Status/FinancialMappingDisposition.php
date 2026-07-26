<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Status;

enum FinancialMappingDisposition: string
{
    case ACTIVE = 'active';
    case RETIRED = 'retired';
}
