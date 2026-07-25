<?php

declare(strict_types=1);

namespace HiEvents\Exceptions\Stripe;

use RuntimeException;

class StripeLocalPaymentNotFoundException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The Stripe payment is not locally available for webhook reconciliation.');
    }
}
