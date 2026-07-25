<?php

declare(strict_types=1);

namespace HiEvents\Services\Infrastructure\Stripe;

use Stripe\Event;

final class StripeConnectWebhookContract
{
    public const PATH = '/api/public/webhooks/stripe';

    public const EVENT_TYPES = [
        Event::ACCOUNT_UPDATED,
        Event::CHARGE_DISPUTE_CLOSED,
        Event::CHARGE_DISPUTE_CREATED,
        Event::CHARGE_DISPUTE_UPDATED,
        Event::CHARGE_REFUNDED,
        Event::CHARGE_SUCCEEDED,
        Event::CHARGE_UPDATED,
        Event::PAYMENT_INTENT_PAYMENT_FAILED,
        Event::PAYMENT_INTENT_SUCCEEDED,
        Event::PAYOUT_PAID,
        Event::PAYOUT_UPDATED,
        Event::REFUND_CREATED,
        Event::REFUND_UPDATED,
    ];
}
