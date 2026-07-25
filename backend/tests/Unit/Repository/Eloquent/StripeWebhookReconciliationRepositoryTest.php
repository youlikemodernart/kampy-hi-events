<?php

declare(strict_types=1);

namespace Tests\Unit\Repository\Eloquent;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\StripeWebhookReconciliationReason;
use HiEvents\DomainObjects\Enums\StripeWebhookReconciliationStatus;
use HiEvents\Models\StripeWebhookReconciliation;
use HiEvents\Repository\Eloquent\StripeWebhookReconciliationRepository;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\StripeWebhookReconciliationDTO;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use Tests\TestCase;

class StripeWebhookReconciliationRepositoryTest extends TestCase
{
    private string $originalConnection;

    private StripeWebhookReconciliationRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();
        config()->set('database.connections.stripe_reconciliation_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::setDefaultConnection('stripe_reconciliation_test');
        DB::purge('stripe_reconciliation_test');

        Schema::create('stripe_webhook_events', static function (Blueprint $table): void {
            $table->id();
            $table->string('event_id')->unique();
        });
        Schema::create('orders', static function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('stripe_payments', static function (Blueprint $table): void {
            $table->id();
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

        $this->repository = new StripeWebhookReconciliationRepository;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::disconnect('stripe_reconciliation_test');
        DB::setDefaultConnection($this->originalConnection);
        DB::purge('stripe_reconciliation_test');

        parent::tearDown();
    }

    public function test_repeated_missing_local_delivery_increments_attempts_without_payload_storage(): void
    {
        $this->insertEvent('evt_retry');
        $dto = $this->refundDTO('evt_retry', 're_retry');

        $this->repository->recordPending($dto, 'Example\\MissingPayment');
        $this->repository->recordPending($dto, 'Example\\MissingPayment');

        $row = StripeWebhookReconciliation::query()->firstOrFail();
        self::assertSame(StripeWebhookReconciliationStatus::PENDING->value, $row->status);
        self::assertSame(2, $row->attempts);
        self::assertSame('Example\\MissingPayment', $row->last_error_class);
        self::assertArrayNotHasKey('payload', $row->getAttributes());
        self::assertArrayNotHasKey('provider_message', $row->getAttributes());
    }

    public function test_one_charge_refunded_event_can_track_multiple_missing_refunds(): void
    {
        $this->insertEvent('evt_multiple');

        $this->repository->recordPending($this->refundDTO('evt_multiple', 're_one'), 'Missing');
        $this->repository->recordPending($this->refundDTO('evt_multiple', 're_two'), 'Missing');

        self::assertSame(2, StripeWebhookReconciliation::query()->count());
        self::assertEqualsCanonicalizing(
            ['re_one', 're_two'],
            StripeWebhookReconciliation::query()->pluck('refund_id')->all(),
        );
    }

    public function test_successful_replay_resolves_existing_row_and_links_local_state(): void
    {
        $this->insertEvent('evt_resolve');
        $pending = $this->refundDTO('evt_resolve', 're_resolve');
        $this->repository->recordPending($pending, 'Missing');
        DB::table('orders')->insert(['id' => 10]);
        DB::table('stripe_payments')->insert(['id' => 31]);

        $resolved = new StripeWebhookReconciliationDTO(
            eventId: $pending->eventId,
            eventType: $pending->eventType,
            stripeAccountId: $pending->stripeAccountId,
            providerObjectType: $pending->providerObjectType,
            providerObjectId: $pending->providerObjectId,
            paymentIntentId: $pending->paymentIntentId,
            chargeId: $pending->chargeId,
            refundId: $pending->refundId,
            orderId: 10,
            stripePaymentId: 31,
            reason: StripeWebhookReconciliationReason::LOCAL_PAYMENT_MISSING,
        );
        $this->repository->resolveExisting($resolved);

        $row = StripeWebhookReconciliation::query()->firstOrFail();
        self::assertSame(StripeWebhookReconciliationStatus::RESOLVED->value, $row->status);
        self::assertSame(10, $row->order_id);
        self::assertSame(31, $row->stripe_payment_id);
        self::assertNotNull($row->resolved_at);
        self::assertNull($row->last_error_class);
    }

    public function test_aging_is_bounded_and_uses_first_seen_retry_window(): void
    {
        Carbon::setTestNow('2026-07-25 12:00:00');
        foreach (['evt_old_one', 'evt_old_two', 'evt_fresh'] as $eventId) {
            $this->insertEvent($eventId);
            $this->repository->recordPending($this->refundDTO($eventId, 're_'.$eventId), 'Missing');
        }
        StripeWebhookReconciliation::query()
            ->whereIn('event_id', ['evt_old_one', 'evt_old_two'])
            ->update(['first_seen_at' => now()->subHours(73)]);

        self::assertSame(1, $this->repository->agePendingBefore(now()->subHours(72), 1));
        self::assertSame(
            1,
            StripeWebhookReconciliation::query()
                ->where('status', StripeWebhookReconciliationStatus::MANUAL_REVIEW->value)
                ->count(),
        );
        self::assertSame(
            2,
            StripeWebhookReconciliation::query()
                ->where('status', StripeWebhookReconciliationStatus::PENDING->value)
                ->count(),
        );
    }

    public function test_aging_rejects_unbounded_batch_sizes(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->repository->agePendingBefore(now(), 1001);
    }

    private function insertEvent(string $eventId): void
    {
        DB::table('stripe_webhook_events')->insert(['event_id' => $eventId]);
    }

    private function refundDTO(string $eventId, string $refundId): StripeWebhookReconciliationDTO
    {
        return new StripeWebhookReconciliationDTO(
            eventId: $eventId,
            eventType: 'charge.refunded',
            stripeAccountId: 'acct_connected',
            providerObjectType: 'refund',
            providerObjectId: $refundId,
            paymentIntentId: 'pi_test',
            chargeId: 'ch_test',
            refundId: $refundId,
            orderId: null,
            stripePaymentId: null,
            reason: StripeWebhookReconciliationReason::LOCAL_PAYMENT_MISSING,
        );
    }
}
