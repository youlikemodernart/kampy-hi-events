<?php

declare(strict_types=1);

namespace Tests\Unit\Repository\Eloquent;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\OrderEffectEmailKind;
use HiEvents\DomainObjects\Enums\OrderEffectStatus;
use HiEvents\DomainObjects\Enums\OrderEffectType;
use HiEvents\Models\OrderEffectOutbox;
use HiEvents\Repository\Eloquent\OrderEffectOutboxRepository;
use HiEvents\Services\Domain\Order\DTOs\OrderEffectRequestDTO;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class OrderEffectOutboxRepositoryTest extends TestCase
{
    private string $originalConnection;

    private OrderEffectOutboxRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();
        config()->set('database.connections.order_outbox_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::setDefaultConnection('order_outbox_test');
        DB::purge('order_outbox_test');

        Schema::create('orders', static function (Blueprint $table): void {
            $table->id();
        });
        Schema::create('order_effect_outbox', static function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_id')->unique();
            $table->string('business_key')->unique();
            $table->unsignedBigInteger('order_id');
            $table->string('effect_type');
            $table->string('transition_key');
            $table->string('domain_event_type')->nullable();
            $table->string('email_kind')->nullable();
            $table->string('status');
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('claimed_at')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('manual_review_at')->nullable();
            $table->string('last_error_class')->nullable();
            $table->timestamps();
        });
        DB::table('orders')->insert(['id' => 10]);
        $this->repository = new OrderEffectOutboxRepository;
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        DB::disconnect('order_outbox_test');
        DB::setDefaultConnection($this->originalConnection);
        DB::purge('order_outbox_test');

        parent::tearDown();
    }

    public function test_enqueue_is_deterministic_idempotent_and_payload_free(): void
    {
        $effect = new OrderEffectRequestDTO(
            OrderEffectType::WEBHOOK,
            domainEventType: DomainEventType::ORDER_CREATED,
        );

        $first = $this->repository->enqueue(10, 'FREE_COMPLETED', $effect);
        $second = $this->repository->enqueue(10, 'FREE_COMPLETED', $effect);

        self::assertSame($first, $second);
        self::assertSame(1, OrderEffectOutbox::query()->count());
        $attributes = OrderEffectOutbox::query()->firstOrFail()->getAttributes();
        self::assertArrayNotHasKey('payload', $attributes);
        self::assertArrayNotHasKey('email', $attributes);
        self::assertArrayNotHasKey('provider_message', $attributes);
    }

    public function test_claim_uses_stable_delivery_id_and_token_fences_completion(): void
    {
        $deliveryId = $this->repository->enqueue(
            10,
            'FREE_COMPLETED',
            new OrderEffectRequestDTO(OrderEffectType::STATISTICS),
        );

        $claim = $this->repository->claimBatch(1, now()->subMinutes(15))->sole();

        self::assertSame($deliveryId, $claim->deliveryId);
        self::assertSame(1, $claim->attempts);
        self::assertFalse($this->repository->markDelivered($claim->id, 'stale-token'));
        self::assertTrue($this->repository->markDelivered($claim->id, $claim->claimToken));
        self::assertSame(OrderEffectStatus::DELIVERED->value, OrderEffectOutbox::query()->firstOrFail()->status);
    }

    public function test_failed_claim_retries_then_ages_to_manual_review(): void
    {
        Carbon::setTestNow('2026-07-25 12:00:00');
        $this->repository->enqueue(
            10,
            'FREE_COMPLETED',
            new OrderEffectRequestDTO(
                OrderEffectType::EMAIL,
                emailKind: OrderEffectEmailKind::DETAILS_AND_TICKETS,
            ),
        );
        $claim = $this->repository->claimBatch(1, now()->subMinutes(15))->sole();
        self::assertTrue($this->repository->markFailed($claim->id, $claim->claimToken, 'Example\\Failure', 2));
        $row = OrderEffectOutbox::query()->firstOrFail();
        self::assertSame(OrderEffectStatus::RETRYABLE->value, $row->status);
        self::assertSame('Example\\Failure', $row->last_error_class);

        $row->update(['available_at' => now()->subSecond()]);
        $retry = $this->repository->claimBatch(1, now()->subMinutes(15))->sole();
        self::assertSame($claim->deliveryId, $retry->deliveryId);
        self::assertTrue($this->repository->markFailed($retry->id, $retry->claimToken, 'Example\\Failure', 2));
        self::assertSame(
            OrderEffectStatus::MANUAL_REVIEW->value,
            OrderEffectOutbox::query()->firstOrFail()->status,
        );
    }

    public function test_stale_processing_claim_is_recovered_with_new_token(): void
    {
        $this->repository->enqueue(10, 'FREE_COMPLETED', new OrderEffectRequestDTO(OrderEffectType::STATISTICS));
        $first = $this->repository->claimBatch(1, now()->subMinutes(15))->sole();
        OrderEffectOutbox::query()->update(['claimed_at' => now()->subMinutes(16)]);

        $recovered = $this->repository->claimBatch(1, now()->subMinutes(15))->sole();

        self::assertSame($first->deliveryId, $recovered->deliveryId);
        self::assertNotSame($first->claimToken, $recovered->claimToken);
        self::assertFalse($this->repository->markDelivered($first->id, $first->claimToken));
        self::assertTrue($this->repository->markDelivered($recovered->id, $recovered->claimToken));
    }
}
