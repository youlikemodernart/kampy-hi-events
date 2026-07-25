<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Infrastructure\Stripe;

use HiEvents\Services\Infrastructure\Stripe\StripeConnectWebhookContract;
use PHPUnit\Framework\TestCase;

class StripeConnectWebhookContractTest extends TestCase
{
    public function test_canonical_path_and_event_allowlist_cover_financial_lifecycle(): void
    {
        self::assertSame('/api/public/webhooks/stripe', StripeConnectWebhookContract::PATH);
        self::assertSame([
            'account.updated',
            'charge.dispute.closed',
            'charge.dispute.created',
            'charge.dispute.updated',
            'charge.refunded',
            'charge.succeeded',
            'charge.updated',
            'payment_intent.payment_failed',
            'payment_intent.succeeded',
            'payout.paid',
            'payout.updated',
            'refund.created',
            'refund.updated',
        ], StripeConnectWebhookContract::EVENT_TYPES);
    }
}
