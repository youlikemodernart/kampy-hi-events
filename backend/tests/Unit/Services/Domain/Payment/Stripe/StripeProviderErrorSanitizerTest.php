<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Payment\Stripe;

use HiEvents\Services\Domain\Payment\Stripe\StripeProviderErrorSanitizer;
use PHPUnit\Framework\TestCase;
use Stripe\StripeObject;

class StripeProviderErrorSanitizerTest extends TestCase
{
    public function test_keeps_only_allowlisted_classification_fields(): void
    {
        $error = StripeObject::constructFrom([
            'type' => 'card_error',
            'code' => 'card_declined',
            'decline_code' => 'generic_decline',
            'message' => 'private provider detail',
            'payment_method' => ['billing_details' => ['email' => 'private@example.invalid']],
        ]);

        self::assertSame([
            'type' => 'card_error',
            'code' => 'card_declined',
            'decline_code' => 'generic_decline',
        ], StripeProviderErrorSanitizer::sanitize($error));
    }

    public function test_returns_null_when_no_safe_classification_exists(): void
    {
        self::assertNull(StripeProviderErrorSanitizer::sanitize(
            StripeObject::constructFrom(['message' => 'private provider detail']),
        ));
        self::assertNull(StripeProviderErrorSanitizer::sanitize(null));
    }
}
