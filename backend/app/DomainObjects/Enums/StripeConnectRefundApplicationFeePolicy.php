<?php

declare(strict_types=1);

namespace HiEvents\DomainObjects\Enums;

enum StripeConnectRefundApplicationFeePolicy: string
{
    case RETAIN = 'retain';
    case RETURN = 'return';
}
