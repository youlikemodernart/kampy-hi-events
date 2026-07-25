<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum StripeWebhookReconciliationReason: string
{
    case LOCAL_PAYMENT_MISSING = 'LOCAL_PAYMENT_MISSING';
    case PAID_TERMINAL_FAILURE_IGNORED = 'PAID_TERMINAL_FAILURE_IGNORED';
    case PAID_STATE_INCONSISTENT = 'PAID_STATE_INCONSISTENT';
}
