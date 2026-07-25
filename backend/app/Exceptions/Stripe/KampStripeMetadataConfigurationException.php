<?php

namespace HiEvents\Exceptions\Stripe;

class KampStripeMetadataConfigurationException extends CreatePaymentIntentFailedException
{
    public function __construct(private readonly string $reason)
    {
        parent::__construct('Payment configuration is incomplete. Please contact the event organizer.');
    }

    public function getReason(): string
    {
        return $this->reason;
    }
}
