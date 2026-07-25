<?php

declare(strict_types=1);

namespace Tests\Unit\Repository\Eloquent;

use HiEvents\Exceptions\StripeWebhookEventClaimBusyException;
use HiEvents\Models\StripeWebhookEvent;
use HiEvents\Repository\Eloquent\StripeWebhookEventRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class StripeWebhookEventRepositoryTest extends TestCase
{
    private string $originalConnection;

    private StripeWebhookEventRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();
        config()->set('database.connections.stripe_webhook_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::setDefaultConnection('stripe_webhook_test');
        DB::purge('stripe_webhook_test');

        Schema::create('stripe_webhook_events', static function (Blueprint $table): void {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('event_type');
            $table->string('stripe_account_id')->nullable();
            $table->string('status', 20);
            $table->uuid('claim_token');
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->string('last_error_class')->nullable();
            $table->timestamps();
        });

        $this->repository = new StripeWebhookEventRepository;
    }

    protected function tearDown(): void
    {
        DB::disconnect('stripe_webhook_test');
        DB::setDefaultConnection($this->originalConnection);
        DB::purge('stripe_webhook_test');

        parent::tearDown();
    }

    public function test_handled_event_identity_survives_repeated_claims(): void
    {
        $claimToken = $this->repository->claim(
            'evt_durable',
            'payment_intent.succeeded',
            'acct_connected',
        );
        self::assertIsString($claimToken);

        $this->repository->markHandled('evt_durable', $claimToken);

        self::assertNull($this->repository->claim(
            'evt_durable',
            'payment_intent.succeeded',
            'acct_connected',
        ));

        $event = StripeWebhookEvent::query()->where('event_id', 'evt_durable')->firstOrFail();
        self::assertSame('HANDLED', $event->status);
        self::assertSame($claimToken, $event->claim_token);
        self::assertSame(1, $event->attempts);
        self::assertNotNull($event->handled_at);
    }

    public function test_failed_event_can_be_claimed_again_without_storing_provider_payload(): void
    {
        $firstToken = $this->repository->claim('evt_retry', 'refund.created', null);
        self::assertIsString($firstToken);
        $this->repository->markFailed('evt_retry', $firstToken, 'Example\\ProviderException');

        $secondToken = $this->repository->claim('evt_retry', 'refund.created', null);
        self::assertIsString($secondToken);
        self::assertNotSame($firstToken, $secondToken);

        $event = StripeWebhookEvent::query()->where('event_id', 'evt_retry')->firstOrFail();
        self::assertSame('PROCESSING', $event->status);
        self::assertSame($secondToken, $event->claim_token);
        self::assertSame(2, $event->attempts);
        self::assertNull($event->handled_at);
        self::assertNull($event->last_error_class);
        self::assertArrayNotHasKey('payload', $event->getAttributes());
    }

    public function test_stale_worker_cannot_finalize_newer_claim(): void
    {
        $staleToken = $this->repository->claim('evt_stale', 'charge.updated', 'acct_connected');
        self::assertIsString($staleToken);
        try {
            $this->repository->claim('evt_stale', 'charge.updated', 'acct_connected');
            self::fail('An active claim was incorrectly acknowledged as handled.');
        } catch (StripeWebhookEventClaimBusyException) {
            self::addToAssertionCount(1);
        }

        StripeWebhookEvent::query()
            ->where('event_id', 'evt_stale')
            ->update(['claimed_at' => now()->subMinutes(16)]);

        $activeToken = $this->repository->claim('evt_stale', 'charge.updated', 'acct_connected');
        self::assertIsString($activeToken);
        self::assertNotSame($staleToken, $activeToken);

        $this->repository->markFailed('evt_stale', $staleToken, RuntimeException::class);

        try {
            $this->repository->markHandled('evt_stale', $staleToken);
            self::fail('A stale claim unexpectedly finalized the event.');
        } catch (RuntimeException) {
            self::addToAssertionCount(1);
        }

        $event = StripeWebhookEvent::query()->where('event_id', 'evt_stale')->firstOrFail();
        self::assertSame('PROCESSING', $event->status);
        self::assertSame($activeToken, $event->claim_token);
        self::assertSame(2, $event->attempts);

        $this->repository->markHandled('evt_stale', $activeToken);
        self::assertSame(
            'HANDLED',
            StripeWebhookEvent::query()->where('event_id', 'evt_stale')->value('status'),
        );
    }
}
