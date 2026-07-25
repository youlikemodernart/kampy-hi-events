<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum OrderEffectStatus: string
{
    case PENDING = 'PENDING';
    case PROCESSING = 'PROCESSING';
    case RETRYABLE = 'RETRYABLE';
    case DELIVERED = 'DELIVERED';
    case MANUAL_REVIEW = 'MANUAL_REVIEW';
}
