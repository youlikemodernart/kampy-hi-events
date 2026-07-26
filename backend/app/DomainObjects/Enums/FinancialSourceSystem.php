<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum FinancialSourceSystem: string
{
    case GOOGLE_SHEET = 'google_sheet';
    case SPARK = 'spark';
    case STRIPE = 'stripe';
    case DONORBOX = 'donorbox';
    case HI_EVENTS = 'hi_events';
}
