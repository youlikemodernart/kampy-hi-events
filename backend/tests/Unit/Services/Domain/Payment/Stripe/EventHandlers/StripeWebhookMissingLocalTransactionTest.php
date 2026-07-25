<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Payment\Stripe\EventHandlers;

use HiEvents\DomainObjects\Enums\StripeWebhookReconciliationStatus;
use HiEvents\Exceptions\Stripe\StripeLocalPaymentNotFoundException;
use HiEvents\Models\StripeWebhookReconciliation;
use HiEvents\Repository\Eloquent\StripeWebhookReconciliationRepository;
use HiEvents\Repository\Interfaces\OrderRefundRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\StripePaymentsRepositoryInterface;
use HiEvents\Repository\Interfaces\StripeRefundRequestRepositoryInterface;
use HiEvents\Services\Domain\EventStatistics\EventStatisticsRefundService;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\ChargeRefundUpdatedHandler;
use HiEvents\Services\Domain\Payment\Stripe\StripeProviderObjectLockService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Log\Logger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Stripe\Refund;
use Tests\TestCase;

class StripeWebhookMissingLocalTransactionTest extends TestCase
{
    private string $originalConnection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();
        config()->set('database.connections.stripe_missing_local_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::setDefaultConnection('stripe_missing_local_test');
        DB::purge('stripe_missing_local_test');

        Schema::create('stripe_webhook_events', static function (Blueprint $table): void {
            $table->id();
            $table->string('event_id')->unique();
        });
        Schema::create('stripe_webhook_reconciliations', static function (Blueprint $table): void {
            $table->id();
            $table->string('event_id');
            $table->string('event_type');
            $table->string('stripe_account_id')->nullable();
            $table->string('provider_object_type');
            $table->string('provider_object_id');
            $table->string('payment_intent_id')->nullable();
            $table->string('charge_id')->nullable();
            $table->string('refund_id')->nullable();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('stripe_payment_id')->nullable();
            $table->string('reason_code');
            $table->string('status');
            $table->unsignedInteger('attempts');
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('manual_review_at')->nullable();
            $table->string('last_error_class')->nullable();
            $table->timestamps();
            $table->unique(['event_id', 'provider_object_type', 'provider_object_id']);
        });
        DB::table('stripe_webhook_events')->insert(['event_id' => 'evt_transaction']);
    }

    protected function tearDown(): void
    {
        DB::disconnect('stripe_missing_local_test');
        DB::setDefaultConnection($this->originalConnection);
        DB::purge('stripe_missing_local_test');
        parent::tearDown();
    }

    public function test_pending_reconciliation_commits_before_retryable_exception_escapes(): void
    {
        $payments = Mockery::mock(StripePaymentsRepositoryInterface::class);
        $payments->shouldReceive('findFirstWhere')->once()->andReturnNull();
        $handler = new ChargeRefundUpdatedHandler(
            Mockery::mock(OrderRepositoryInterface::class),
            $payments,
            Mockery::mock(Logger::class),
            app(DatabaseManager::class),
            Mockery::mock(EventStatisticsRefundService::class),
            Mockery::mock(OrderRefundRepositoryInterface::class),
            Mockery::mock(DomainEventDispatcherService::class),
            new StripeWebhookReconciliationRepository,
            new StripeProviderObjectLockService(app(DatabaseManager::class)),
            Mockery::mock(StripeRefundRequestRepositoryInterface::class),
        );
        $refund = Refund::constructFrom([
            'id' => 're_transaction',
            'object' => 'refund',
            'amount' => 1000,
            'currency' => 'usd',
            'payment_intent' => 'pi_transaction',
            'charge' => 'ch_transaction',
            'status' => 'succeeded',
        ]);

        try {
            $handler->handleEvent(
                $refund,
                'acct_connected',
                'evt_transaction',
                'refund.created',
            );
            self::fail('The missing local refund was unexpectedly acknowledged.');
        } catch (StripeLocalPaymentNotFoundException) {
            $row = StripeWebhookReconciliation::query()->firstOrFail();
            self::assertSame(StripeWebhookReconciliationStatus::PENDING->value, $row->status);
            self::assertSame('re_transaction', $row->provider_object_id);
            self::assertSame(1, $row->attempts);
            self::assertSame(0, DB::connection()->transactionLevel());
        }
    }
}
