<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum FinancialSourceObjectKind: string
{
    case PLAN_RECORD = 'plan_record';
    case TICKET_EVENT = 'ticket_event';
    case DONATION_CAMPAIGN = 'donation_campaign';
}
