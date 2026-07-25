<?php

declare(strict_types=1);

namespace Tests\Unit\Repository;

use HiEvents\DomainObjects\Enums\StripeRefundRequestStatus;
use HiEvents\Exceptions\Stripe\StripeRefundRequestConflictException;
use HiEvents\Repository\Eloquent\StripeRefundRequestRepository;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\CreateStripeRefundRequestDTO;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StripeRefundRequestRepositoryTest extends TestCase
{
    private string $originalConnection;

    private StripeRefundRequestRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->originalConnection = DB::getDefaultConnection();
        config()->set('database.connections.stripe_refund_request_test', [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ]);
        DB::setDefaultConnection('stripe_refund_request_test');
        DB::purge('stripe_refund_request_test');

        Schema::create('stripe_refund_requests', static function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('stripe_payment_id');
            $table->string('payment_intent_id');
            $table->string('stripe_account_id')->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 10);
            $table->boolean('notify_buyer');
            $table->boolean('cancel_order');
            $table->boolean('refund_application_fee')->nullable();
            $table->string('status', 32);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('provider_refund_id')->nullable()->unique();
            $table->string('provider_status', 32)->nullable();
            $table->string('last_error_class')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('provider_accepted_at')->nullable();
            $table->timestamp('cancel_applied_at')->nullable();
            $table->timestamp('notification_claimed_at')->nullable();
            $table->timestamp('notification_sent_at')->nullable();
            $table->timestamps();
        });

        $this->repository = new StripeRefundRequestRepository;
    }

    protected function tearDown(): void
    {
        DB::disconnect('stripe_refund_request_test');
        DB::setDefaultConnection($this->originalConnection);
        DB::purge('stripe_refund_request_test');
        parent::tearDown();
    }

    public function test_same_request_and_payload_loads_one_durable_identity(): void
    {
        $request = $this->request('0f51dbea-f04b-4a39-8d84-e861aac14e55');

        $first = DB::transaction(fn () => $this->repository->claimOrLoad($request));
        $second = DB::transaction(fn () => $this->repository->claimOrLoad($request));

        self::assertTrue($first->created);
        self::assertFalse($second->created);
        self::assertSame($first->request->id, $second->request->id);
        self::assertSame(1, DB::table('stripe_refund_requests')->count());
    }

    public function test_same_request_with_changed_payload_fails_closed(): void
    {
        $requestId = '0f51dbea-f04b-4a39-8d84-e861aac14e55';
        DB::transaction(fn () => $this->repository->claimOrLoad($this->request($requestId)));

        $this->expectException(StripeRefundRequestConflictException::class);
        DB::transaction(fn () => $this->repository->claimOrLoad(
            $this->request($requestId, amountMinor: 2000)
        ));
    }

    public function test_two_request_ids_allow_two_intentional_equal_partial_refunds(): void
    {
        $first = DB::transaction(fn () => $this->repository->claimOrLoad(
            $this->request('0f51dbea-f04b-4a39-8d84-e861aac14e55')
        ));
        $second = DB::transaction(fn () => $this->repository->claimOrLoad(
            $this->request('bf656026-cbc5-471d-ac45-3683fd24cb62')
        ));

        self::assertTrue($first->created);
        self::assertTrue($second->created);
        self::assertSame(2, DB::table('stripe_refund_requests')->count());
    }

    public function test_provider_result_is_idempotent_and_terminal_state_does_not_regress(): void
    {
        $requestId = '0f51dbea-f04b-4a39-8d84-e861aac14e55';
        DB::transaction(fn () => $this->repository->claimOrLoad($this->request($requestId)));

        $accepted = DB::transaction(fn () => $this->repository->recordProviderResult(
            $requestId,
            're_fixture',
            'pending',
        ));
        $succeeded = DB::transaction(fn () => $this->repository->recordProviderResult(
            $requestId,
            're_fixture',
            'succeeded',
            true,
        ));
        $replayed = DB::transaction(fn () => $this->repository->recordProviderResult(
            $requestId,
            're_fixture',
            'pending',
        ));

        self::assertSame(StripeRefundRequestStatus::PROVIDER_ACCEPTED->value, $accepted->status);
        self::assertSame(StripeRefundRequestStatus::SUCCEEDED->value, $succeeded->status);
        self::assertSame(StripeRefundRequestStatus::SUCCEEDED->value, $replayed->status);
        self::assertSame('re_fixture', $replayed->providerRefundId);
    }

    public function test_notification_claim_has_one_winner(): void
    {
        $requestId = '0f51dbea-f04b-4a39-8d84-e861aac14e55';
        DB::transaction(fn () => $this->repository->claimOrLoad($this->request($requestId)));
        DB::transaction(fn () => $this->repository->recordProviderResult(
            $requestId,
            're_fixture',
            'pending',
        ));

        self::assertTrue($this->repository->claimNotification($requestId));
        self::assertFalse($this->repository->claimNotification($requestId));
    }

    private function request(string $requestId, int $amountMinor = 1000): CreateStripeRefundRequestDTO
    {
        return new CreateStripeRefundRequestDTO(
            requestId: $requestId,
            orderId: 10,
            stripePaymentId: 20,
            paymentIntentId: 'pi_fixture',
            stripeAccountId: 'acct_fixture',
            amountMinor: $amountMinor,
            currency: 'USD',
            notifyBuyer: true,
            cancelOrder: false,
            refundApplicationFee: true,
        );
    }
}
