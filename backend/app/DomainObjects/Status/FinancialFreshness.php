<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Status;

enum FinancialFreshness: string
{
    case CURRENT = 'current';
    case STALE = 'stale';
    case UNKNOWN = 'unknown';
}
