<?php

declare(strict_types=1);

namespace HiEvents\Exceptions;

use RuntimeException;

class StripeWebhookEventClaimBusyException extends RuntimeException
{
    public function __construct()
    {
        parent::__construct('The Stripe webhook event has an active processing claim.');
    }
}
