<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Application\Handlers\Order\Payment\Stripe;

use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Exceptions\RefundNotPossibleException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\StripeRefundRequestRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\RefundOrderDTO;
use HiEvents\Services\Application\Handlers\Order\Payment\Stripe\RefundOrderHandler;
use HiEvents\Services\Domain\Order\OrderCancelService;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\StripeRefundRequestClaimDTO;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\StripeRefundRequestRecordDTO;
use HiEvents\Services\Domain\Payment\Stripe\StripePaymentIntentRefundService;
use HiEvents\Services\Domain\Payment\Stripe\StripeProviderObjectLockService;
use HiEvents\Services\Infrastructure\Stripe\StripeClientFactory;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Tests\TestCase;

class RefundOrderHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_replay_of_an_accepted_request_skips_provider_creation(): void
    {
        $order = $this->order();
        $request = $this->request(providerRefundId: 're_fixture');
        $orders = Mockery::mock(OrderRepositoryInterface::class);
        $orders->shouldReceive('loadRelation')->times(3)->andReturnSelf();
        $orders->shouldReceive('findFirstWhere')->twice()->andReturn($order);
        $orders->shouldReceive('findById')->once()->andReturn($order);
        $orders->shouldReceive('updateFromArray')->once()->andReturn($order);

        $refundService = Mockery::mock(StripePaymentIntentRefundService::class);
        $refundService->shouldReceive('resolveRefundApplicationFee')->once()->andReturn(true);
        $refundService->shouldNotReceive('refundPayment');

        $requests = Mockery::mock(StripeRefundRequestRepositoryInterface::class);
        $requests->shouldReceive('claimOrLoad')
            ->once()
            ->andReturn(new StripeRefundRequestClaimDTO($request, false));
        $requests->shouldReceive('findByRequestId')->once()->with($request->requestId, true)->andReturn($request);
        $requests->shouldReceive('claimNotification')->never();

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('transaction')->twice()->andReturnUsing(static fn ($callback) => $callback());
        $lock = Mockery::mock(StripeProviderObjectLockService::class);
        $lock->shouldReceive('acquirePaymentIdentity')->twice();

        $result = $this->handler($refundService, $orders, $requests, $database, $lock)->handle($this->input());

        self::assertSame($order->getId(), $result->getId());
    }

    public function test_waiting_request_refetches_order_state_after_the_provider_lock(): void
    {
        $staleOrder = $this->order();
        $committedOrder = $this->order()->setRefundStatus(OrderRefundStatus::REFUND_PENDING->name);
        $request = $this->request(providerRefundId: null);
        $orders = Mockery::mock(OrderRepositoryInterface::class);
        $orders->shouldReceive('loadRelation')->twice()->andReturnSelf();
        $orders->shouldReceive('findFirstWhere')->twice()->andReturn($staleOrder, $committedOrder);
        $orders->shouldNotReceive('updateFromArray');

        $refundService = Mockery::mock(StripePaymentIntentRefundService::class);
        $refundService->shouldReceive('resolveRefundApplicationFee')->once()->andReturn(true);
        $refundService->shouldNotReceive('refundPayment');

        $requests = Mockery::mock(StripeRefundRequestRepositoryInterface::class);
        $requests->shouldReceive('claimOrLoad')
            ->once()
            ->andReturn(new StripeRefundRequestClaimDTO($request, true));

        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldReceive('transaction')->once()->andReturnUsing(static fn ($callback) => $callback());
        $lock = Mockery::mock(StripeProviderObjectLockService::class);
        $lock->shouldReceive('acquirePaymentIdentity')->once();

        $this->expectException(RefundNotPossibleException::class);
        $this->handler($refundService, $orders, $requests, $database, $lock)->handle($this->input());
    }

    private function handler(
        StripePaymentIntentRefundService $refundService,
        OrderRepositoryInterface $orders,
        StripeRefundRequestRepositoryInterface $requests,
        DatabaseManager $database,
        StripeProviderObjectLockService $lock,
    ): RefundOrderHandler {
        return new RefundOrderHandler(
            $refundService,
            $orders,
            Mockery::mock(EventRepositoryInterface::class),
            Mockery::mock(Mailer::class),
            Mockery::mock(OrderCancelService::class),
            $database,
            Mockery::mock(StripeClientFactory::class),
            $requests,
            $lock,
        );
    }

    private function input(): RefundOrderDTO
    {
        return new RefundOrderDTO(
            refund_request_id: '0f51dbea-f04b-4a39-8d84-e861aac14e55',
            event_id: 3,
            order_id: 10,
            amount: 10.00,
            notify_buyer: false,
            cancel_order: false,
        );
    }

    private function order(): OrderDomainObject
    {
        $payment = (new StripePaymentDomainObject)
            ->setId(20)
            ->setOrderId(10)
            ->setPaymentIntentId('pi_fixture')
            ->setChargeId('ch_fixture')
            ->setConnectedAccountId('acct_fixture');
        $order = (new OrderDomainObject)
            ->setId(10)
            ->setEventId(3)
            ->setCurrency('USD')
            ->setTotalGross(50.00)
            ->setTotalRefunded(0.00)
            ->setRefundStatus(OrderRefundStatus::PARTIALLY_REFUNDED->name)
            ->setEmail('invented@example.invalid')
            ->setLocale('en');
        $order->setStripePayment($payment);

        return $order;
    }

    private function request(?string $providerRefundId): StripeRefundRequestRecordDTO
    {
        return new StripeRefundRequestRecordDTO(
            id: 30,
            requestId: '0f51dbea-f04b-4a39-8d84-e861aac14e55',
            orderId: 10,
            stripePaymentId: 20,
            paymentIntentId: 'pi_fixture',
            stripeAccountId: 'acct_fixture',
            amountMinor: 1000,
            currency: 'USD',
            notifyBuyer: false,
            cancelOrder: false,
            refundApplicationFee: true,
            status: $providerRefundId === null ? 'CREATED' : 'PROVIDER_ACCEPTED',
            attempts: $providerRefundId === null ? 0 : 1,
            providerRefundId: $providerRefundId,
            providerStatus: $providerRefundId === null ? null : 'pending',
            cancelApplied: false,
            notificationClaimed: false,
            notificationSent: false,
        );
    }
}
