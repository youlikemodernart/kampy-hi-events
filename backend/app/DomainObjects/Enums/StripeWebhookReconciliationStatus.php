<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum StripeWebhookReconciliationStatus: string
{
    case PENDING = 'PENDING';
    case RESOLVED = 'RESOLVED';
    case MANUAL_REVIEW = 'MANUAL_REVIEW';
}
