<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Request\Order;

use HiEvents\Http\Request\Order\RefundOrderRequest;
use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class RefundOrderRequestTest extends TestCase
{
    public function test_refund_request_identity_is_required_and_must_be_a_uuid(): void
    {
        $rules = (new RefundOrderRequest)->rules();
        $base = [
            'amount' => 10.00,
            'notify_buyer' => false,
            'cancel_order' => false,
        ];

        self::assertTrue(Validator::make($base, $rules)->fails());
        self::assertTrue(Validator::make([
            ...$base,
            'refund_request_id' => 'not-a-uuid',
        ], $rules)->fails());
        self::assertFalse(Validator::make([
            ...$base,
            'refund_request_id' => '0f51dbea-f04b-4a39-8d84-e861aac14e55',
        ], $rules)->fails());
    }
}
