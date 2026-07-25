<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum OrderEffectEmailKind: string
{
    case DETAILS_AND_TICKETS = 'DETAILS_AND_TICKETS';
    case CUSTOMER_SUMMARY = 'CUSTOMER_SUMMARY';
}
