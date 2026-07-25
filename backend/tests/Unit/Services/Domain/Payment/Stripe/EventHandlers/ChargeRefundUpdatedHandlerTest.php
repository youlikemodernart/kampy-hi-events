<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Payment\Stripe\EventHandlers;

use HiEvents\DomainObjects\Enums\StripeWebhookReconciliationReason;
use HiEvents\DomainObjects\OrderRefundDomainObject;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Exceptions\Stripe\StripeLocalPaymentNotFoundException;
use HiEvents\Repository\Interfaces\OrderRefundRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\StripePaymentsRepositoryInterface;
use HiEvents\Repository\Interfaces\StripeRefundRequestRepositoryInterface;
use HiEvents\Repository\Interfaces\StripeWebhookReconciliationRepositoryInterface;
use HiEvents\Services\Domain\EventStatistics\EventStatisticsRefundService;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\StripeRefundRequestRecordDTO;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\ChargeRefundUpdatedHandler;
use HiEvents\Services\Domain\Payment\Stripe\StripeProviderObjectLockService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Log\Logger;
use Mockery;
use Stripe\Refund;
use Tests\TestCase;

class ChargeRefundUpdatedHandlerTest extends TestCase
{
    public function test_webhook_links_a_durable_refund_request_before_duplicate_result_readback(): void
    {
        $orders = Mockery::mock(OrderRepositoryInterface::class);
        $payments = Mockery::mock(StripePaymentsRepositoryInterface::class);
        $logger = Mockery::mock(Logger::class);
        $database = Mockery::mock(DatabaseManager::class);
        $statistics = Mockery::mock(EventStatisticsRefundService::class);
        $refunds = Mockery::mock(OrderRefundRepositoryInterface::class);
        $events = Mockery::mock(DomainEventDispatcherService::class);
        $reconciliations = Mockery::mock(StripeWebhookReconciliationRepositoryInterface::class);
        $providerLock = Mockery::mock(StripeProviderObjectLockService::class);
        $requests = Mockery::mock(StripeRefundRequestRepositoryInterface::class);
        $database->shouldReceive('transaction')->once()->andReturnUsing(
            static fn ($callback) => $callback(),
        );
        $providerLock->shouldReceive('acquirePaymentIdentity')->once()->with('pi_fixture', 'ch_parent');
        $payment = (new StripePaymentDomainObject)
            ->setId(20)
            ->setOrderId(10)
            ->setPaymentIntentId('pi_fixture')
            ->setChargeId('ch_parent')
            ->setConnectedAccountId('acct_fixture');
        $payments->shouldReceive('findFirstWhere')->once()->andReturn($payment);
        $reconciliations->shouldReceive('resolveExisting')->once();
        $request = new StripeRefundRequestRecordDTO(
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
            status: 'PROVIDER_ACCEPTED',
            attempts: 1,
            providerRefundId: 're_fixture',
            providerStatus: 'pending',
            cancelApplied: false,
            notificationClaimed: false,
            notificationSent: false,
        );
        $requests->shouldReceive('findByRequestId')
            ->once()
            ->with($request->requestId, true)
            ->andReturn($request);
        $requests->shouldReceive('recordProviderResult')
            ->once()
            ->with($request->requestId, 're_fixture', 'succeeded', true)
            ->andReturn($request);
        $refunds->shouldReceive('findFirstWhere')
            ->once()
            ->andReturn((new OrderRefundDomainObject)->setId(40));
        $logger->shouldReceive('info')->once();
        $orders->shouldNotReceive('updateFromArray');
        $statistics->shouldNotReceive('updateForRefund');
        $events->shouldNotReceive('dispatch');

        $handler = new ChargeRefundUpdatedHandler(
            $orders,
            $payments,
            $logger,
            $database,
            $statistics,
            $refunds,
            $events,
            $reconciliations,
            $providerLock,
            $requests,
        );
        $refund = Refund::constructFrom([
            'id' => 're_fixture',
            'amount' => 1000,
            'currency' => 'usd',
            'payment_intent' => 'pi_fixture',
            'charge' => 'ch_child',
            'status' => 'succeeded',
            'metadata' => ['hie_refund_request_id' => $request->requestId],
        ]);

        $handler->handleEvent($refund, 'acct_fixture', 'evt_fixture', 'refund.updated', 'ch_parent');
    }

    public function test_missing_local_payment_is_durable_and_retryable_instead_of_acknowledged(): void
    {
        $orders = Mockery::mock(OrderRepositoryInterface::class);
        $payments = Mockery::mock(StripePaymentsRepositoryInterface::class);
        $logger = Mockery::mock(Logger::class);
        $database = Mockery::mock(DatabaseManager::class);
        $statistics = Mockery::mock(EventStatisticsRefundService::class);
        $refunds = Mockery::mock(OrderRefundRepositoryInterface::class);
        $events = Mockery::mock(DomainEventDispatcherService::class);
        $reconciliations = Mockery::mock(StripeWebhookReconciliationRepositoryInterface::class);
        $providerLock = Mockery::mock(StripeProviderObjectLockService::class);
        $insideTransaction = false;
        $database->shouldReceive('transaction')->once()->andReturnUsing(
            static function ($callback) use (&$insideTransaction) {
                $insideTransaction = true;

                try {
                    return $callback();
                } finally {
                    $insideTransaction = false;
                }
            },
        );
        $providerLock->shouldReceive('acquirePaymentIdentity')->once()->with('pi_missing', 'ch_parent');
        $payments->shouldReceive('findFirstWhere')
            ->once()
            ->with(['payment_intent_id' => 'pi_missing'])
            ->andReturnNull();
        $reconciliations->shouldReceive('recordPending')
            ->once()
            ->withArgs(static function ($dto, string $errorClass) use (&$insideTransaction): bool {
                return $insideTransaction
                    && $dto->eventId === 'evt_refunded'
                    && $dto->providerObjectId === 're_missing'
                    && $dto->chargeId === 'ch_parent'
                    && $dto->reason === StripeWebhookReconciliationReason::LOCAL_PAYMENT_MISSING
                    && $errorClass === StripeLocalPaymentNotFoundException::class;
            });
        $orders->shouldNotReceive('updateFromArray');
        $refunds->shouldNotReceive('create');
        $statistics->shouldNotReceive('updateForRefund');
        $events->shouldNotReceive('dispatch');

        $handler = new ChargeRefundUpdatedHandler(
            $orders,
            $payments,
            $logger,
            $database,
            $statistics,
            $refunds,
            $events,
            $reconciliations,
            $providerLock,
            Mockery::mock(StripeRefundRequestRepositoryInterface::class),
        );
        $refund = Refund::constructFrom([
            'id' => 're_missing',
            'object' => 'refund',
            'amount' => 5695,
            'currency' => 'usd',
            'payment_intent' => 'pi_missing',
            'charge' => 'ch_child',
            'status' => 'succeeded',
        ]);

        $this->expectException(StripeLocalPaymentNotFoundException::class);
        $handler->handleEvent(
            $refund,
            'acct_connected',
            'evt_refunded',
            'charge.refunded',
            'ch_parent',
        );
    }
}
