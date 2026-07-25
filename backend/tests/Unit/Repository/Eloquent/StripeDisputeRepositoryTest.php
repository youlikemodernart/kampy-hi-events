<?php

declare(strict_types=1);

namespace Tests\Unit\Repository\Eloquent;

use DateTimeImmutable;
use HiEvents\Models\StripeDispute;
use HiEvents\Repository\Eloquent\StripeDisputeRepository;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\StripeDisputeDTO;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StripeDisputeRepositoryTest extends TestCase
{
    private string $originalConnection;

    private StripeDisputeRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();
        config()->set('database.connections.stripe_dispute_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => false,
        ]);
        DB::setDefaultConnection('stripe_dispute_test');
        DB::purge('stripe_dispute_test');

        Schema::create('stripe_disputes', static function (Blueprint $table): void {
            $table->id();
            $table->string('dispute_id')->unique();
            $table->unsignedBigInteger('order_id')->nullable();
            $table->unsignedBigInteger('stripe_payment_id')->nullable();
            $table->string('payment_intent_id')->nullable();
            $table->string('charge_id')->nullable();
            $table->string('stripe_account_id')->nullable();
            $table->bigInteger('amount_minor');
            $table->string('currency', 10);
            $table->string('status', 50);
            $table->string('reason', 100)->nullable();
            $table->json('balance_transaction_ids');
            $table->timestamp('evidence_due_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('provider_created_at')->nullable();
            $table->string('last_event_id');
            $table->string('last_event_type');
            $table->timestamp('last_event_created_at');
            $table->timestamps();
        });

        $this->repository = new StripeDisputeRepository;
    }

    protected function tearDown(): void
    {
        DB::disconnect('stripe_dispute_test');
        DB::setDefaultConnection($this->originalConnection);
        DB::purge('stripe_dispute_test');

        parent::tearDown();
    }

    public function test_upsert_advances_one_durable_dispute_through_lifecycle(): void
    {
        $this->repository->upsert($this->dto(
            status: 'needs_response',
            eventCreatedAt: new DateTimeImmutable('@1800000200'),
        ));
        $this->repository->upsert($this->dto(
            status: 'won',
            closedAt: new DateTimeImmutable('@1800000300'),
            eventCreatedAt: new DateTimeImmutable('@1800000300'),
        ));
        $this->repository->upsert($this->dto(
            status: 'lost',
            closedAt: new DateTimeImmutable('@1800000250'),
            eventCreatedAt: new DateTimeImmutable('@1800000250'),
        ));
        $this->repository->upsert($this->dto(
            status: 'needs_response',
            eventCreatedAt: new DateTimeImmutable('@1800000300'),
        ));

        self::assertSame(1, StripeDispute::query()->count());

        $dispute = StripeDispute::query()->where('dispute_id', 'du_test_lifecycle')->firstOrFail();
        self::assertSame('won', $dispute->status);
        self::assertSame(10, $dispute->order_id);
        self::assertSame(31, $dispute->stripe_payment_id);
        self::assertSame(5695, $dispute->amount_minor);
        self::assertSame(['txn_test_dispute'], $dispute->balance_transaction_ids);
        self::assertSame(1_800_000_300, $dispute->closed_at?->getTimestamp());
        self::assertSame('evt_won_1800000300', $dispute->last_event_id);
        self::assertSame(1_800_000_300, $dispute->last_event_created_at?->getTimestamp());
        self::assertArrayNotHasKey('payload', $dispute->getAttributes());
    }

    public function test_link_pending_to_payment_backfills_only_detached_matching_disputes(): void
    {
        $this->repository->upsert($this->dto(
            status: 'needs_response',
            eventCreatedAt: new DateTimeImmutable('@1800000200'),
        ));
        StripeDispute::query()
            ->where('dispute_id', 'du_test_lifecycle')
            ->update(['order_id' => null, 'stripe_payment_id' => null]);

        StripeDispute::query()->create([
            'dispute_id' => 'du_unrelated',
            'payment_intent_id' => 'pi_test_disputed',
            'charge_id' => 'ch_test_disputed',
            'stripe_account_id' => 'acct_other_connected',
            'amount_minor' => 100,
            'currency' => 'usd',
            'status' => 'needs_response',
            'balance_transaction_ids' => [],
            'last_event_id' => 'evt_unrelated',
            'last_event_type' => 'charge.dispute.created',
            'last_event_created_at' => new DateTimeImmutable('@1800000200'),
        ]);

        $linked = $this->repository->linkPendingToPayment(
            orderId: 10,
            stripePaymentId: 31,
            paymentIntentId: 'pi_test_disputed',
            chargeId: 'ch_test_disputed',
            stripeAccountId: 'acct_test_connected',
        );

        self::assertSame(1, $linked);
        self::assertSame(10, StripeDispute::query()->where('dispute_id', 'du_test_lifecycle')->value('order_id'));
        self::assertSame(31, StripeDispute::query()->where('dispute_id', 'du_test_lifecycle')->value('stripe_payment_id'));
        self::assertNull(StripeDispute::query()->where('dispute_id', 'du_unrelated')->value('order_id'));
    }

    private function dto(
        string $status,
        ?DateTimeImmutable $closedAt = null,
        ?DateTimeImmutable $eventCreatedAt = null,
    ): StripeDisputeDTO {
        $eventCreatedAt ??= new DateTimeImmutable('@1800000000');

        return new StripeDisputeDTO(
            disputeId: 'du_test_lifecycle',
            orderId: 10,
            stripePaymentId: 31,
            paymentIntentId: 'pi_test_disputed',
            chargeId: 'ch_test_disputed',
            stripeAccountId: 'acct_test_connected',
            amountMinor: 5695,
            currency: 'usd',
            status: $status,
            reason: 'fraudulent',
            balanceTransactionIds: ['txn_test_dispute'],
            evidenceDueAt: new DateTimeImmutable('@1800000000'),
            closedAt: $closedAt,
            providerCreatedAt: new DateTimeImmutable('@1700000000'),
            lastEventId: 'evt_'.$status.'_'.$eventCreatedAt->getTimestamp(),
            lastEventType: $closedAt === null ? 'charge.dispute.updated' : 'charge.dispute.closed',
            lastEventCreatedAt: $eventCreatedAt,
        );
    }
}
