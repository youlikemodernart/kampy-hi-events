<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum StripeWebhookAdmissionDisposition: string
{
    case LOCAL = 'LOCAL';
    case FOREIGN = 'FOREIGN';
    case AMBIGUOUS = 'AMBIGUOUS';
}
