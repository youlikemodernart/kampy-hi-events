<?php

namespace Tests\Unit\Repository\Eloquent;

use HiEvents\Repository\Eloquent\OrderPurchaseContactRepository;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderPurchaseContactRepositoryTest extends TestCase
{
    public function test_capture_defaults_off_and_intake_enablement_does_not_query_lock_or_encrypt(): void
    {
        self::assertFalse(config('respondent-confirmation.capture_enabled'));
        DB::shouldReceive('table')->never();
        $this->app->bind('encrypter', function () {
            self::fail('Capture must not resolve encryption');
        });
        $repository = new OrderPurchaseContactRepository;
        foreach ([false, true] as $intakeEnabled) {
            config()->set('respondent-confirmation.enabled', $intakeEnabled);
            $repository->lockCheckout('synthetic-order');
            $repository->capture(11, 7, 'buyer@example.test');
        }
    }

    public function test_capture_requires_explicit_boolean_true_before_any_database_or_encryption_effect(): void
    {
        DB::shouldReceive('table')->never();
        $this->app->bind('encrypter', function () {
            self::fail('Capture must not resolve encryption');
        });
        $repository = new OrderPurchaseContactRepository;
        foreach ([null, false, 'invalid', 1] as $disabledValue) {
            config()->set('respondent-confirmation.capture_enabled', $disabledValue);
            $repository->lockCheckout('synthetic-order');
            $repository->capture(11, 7, 'buyer@example.test');
        }
    }

    public function test_enabled_capture_does_not_insert_for_other_events(): void
    {
        config()->set('respondent-confirmation.capture_enabled', true);
        DB::shouldReceive('table')->never();
        $this->app->bind('encrypter', function () {
            self::fail('Capture must not resolve encryption');
        });
        (new OrderPurchaseContactRepository)->capture(11, 8, 'buyer@example.test');
    }
}
