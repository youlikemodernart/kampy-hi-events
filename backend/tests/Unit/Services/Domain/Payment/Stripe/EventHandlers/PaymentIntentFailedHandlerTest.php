<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Payment\Stripe\EventHandlers;

use HiEvents\DomainObjects\Enums\StripeWebhookReconciliationReason;
use HiEvents\DomainObjects\Enums\StripeWebhookReconciliationStatus;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Exceptions\Stripe\StripeLocalPaymentNotFoundException;
use HiEvents\Repository\Eloquent\StripePaymentsRepository;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\StripeWebhookReconciliationRepositoryInterface;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\PaymentIntentFailedHandler;
use HiEvents\Services\Domain\Payment\Stripe\StripePaymentUpdateFromPaymentIntentService;
use HiEvents\Services\Domain\Payment\Stripe\StripeProviderObjectLockService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Stripe\PaymentIntent;
use Tests\TestCase;

class PaymentIntentFailedHandlerTest extends TestCase
{
    private OrderRepositoryInterface|MockInterface $orders;

    private StripePaymentsRepository|MockInterface $payments;

    private DatabaseManager|MockInterface $database;

    private StripePaymentUpdateFromPaymentIntentService $paymentUpdater;

    private StripeWebhookReconciliationRepositoryInterface|MockInterface $reconciliations;

    private StripeProviderObjectLockService|MockInterface $providerLock;

    private LoggerInterface|MockInterface $logger;

    private PaymentIntentFailedHandler $handler;

    private bool $insideTransaction = false;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = Mockery::mock(OrderRepositoryInterface::class);
        $this->payments = Mockery::mock(StripePaymentsRepository::class);
        $this->database = Mockery::mock(DatabaseManager::class);
        $this->paymentUpdater = new StripePaymentUpdateFromPaymentIntentService($this->payments);
        $this->reconciliations = Mockery::mock(StripeWebhookReconciliationRepositoryInterface::class);
        $this->providerLock = Mockery::mock(StripeProviderObjectLockService::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->database->shouldReceive('transaction')->andReturnUsing(function ($callback) {
            $this->insideTransaction = true;

            try {
                return $callback();
            } finally {
                $this->insideTransaction = false;
            }
        });

        $this->handler = new PaymentIntentFailedHandler(
            $this->orders,
            $this->payments,
            $this->database,
            $this->paymentUpdater,
            $this->reconciliations,
            $this->providerLock,
            $this->logger,
        );
    }

    public function test_missing_local_payment_records_pending_reconciliation_and_throws_retryable_exception(): void
    {
        $intent = $this->paymentIntent('pi_missing', 'ch_missing');

        $this->providerLock->shouldReceive('acquirePaymentIdentity')->once()->with('pi_missing', 'ch_missing');
        $this->payments->shouldReceive('loadRelation')->once()->andReturnSelf();
        $this->payments->shouldReceive('findFirstWhere')->once()->andReturnNull();
        $this->reconciliations->shouldReceive('recordPending')
            ->once()
            ->withArgs(fn ($dto, string $errorClass): bool => $this->insideTransaction
                && $dto->eventId === 'evt_missing'
                && $dto->providerObjectId === 'pi_missing'
                && $dto->reason === StripeWebhookReconciliationReason::LOCAL_PAYMENT_MISSING
                && $errorClass === StripeLocalPaymentNotFoundException::class);
        $this->payments->shouldNotReceive('updateWhere');
        $this->orders->shouldNotReceive('updateFromArray');

        $this->expectException(StripeLocalPaymentNotFoundException::class);
        $this->handler->handleEvent(
            $intent,
            'acct_connected',
            'evt_missing',
            'payment_intent.payment_failed',
        );
    }

    public function test_paid_completed_order_is_never_regressed_and_is_recorded_as_resolved_audit(): void
    {
        Event::fake();
        $intent = $this->paymentIntent('pi_paid', 'ch_paid');
        $payment = $this->payment(
            OrderStatus::COMPLETED->name,
            OrderPaymentStatus::PAYMENT_RECEIVED->name,
        );

        $this->providerLock->shouldReceive('acquirePaymentIdentity')->once()->with('pi_paid', 'ch_paid');
        $this->payments->shouldReceive('loadRelation')->once()->andReturnSelf();
        $this->payments->shouldReceive('findFirstWhere')->once()->andReturn($payment);
        $this->reconciliations->shouldReceive('recordAudit')
            ->once()
            ->withArgs(static fn ($dto, StripeWebhookReconciliationStatus $status): bool => $dto->reason
                === StripeWebhookReconciliationReason::PAID_TERMINAL_FAILURE_IGNORED
                && $dto->orderId === 10
                && $dto->stripePaymentId === 31
                && $status === StripeWebhookReconciliationStatus::RESOLVED);
        $this->logger->shouldReceive('warning')->once()->with(
            'Ignored Stripe payment failure for an order with received payment',
            Mockery::type('array'),
        );
        $this->payments->shouldNotReceive('updateWhere');
        $this->orders->shouldNotReceive('updateFromArray');

        $this->handler->handleEvent(
            $intent,
            'acct_connected',
            'evt_paid_failure',
            'payment_intent.payment_failed',
        );

        Event::assertNotDispatched(OrderStatusChangedEvent::class);
    }

    public function test_received_payment_with_inconsistent_order_state_goes_to_manual_review_without_regression(): void
    {
        $intent = $this->paymentIntent('pi_inconsistent', null);
        $payment = $this->payment(
            OrderStatus::RESERVED->name,
            OrderPaymentStatus::PAYMENT_RECEIVED->name,
        );

        $this->providerLock->shouldReceive('acquirePaymentIdentity')->once()->with('pi_inconsistent', null);
        $this->payments->shouldReceive('loadRelation')->once()->andReturnSelf();
        $this->payments->shouldReceive('findFirstWhere')->once()->andReturn($payment);
        $this->reconciliations->shouldReceive('recordAudit')
            ->once()
            ->withArgs(static fn ($dto, StripeWebhookReconciliationStatus $status): bool => $dto->reason
                === StripeWebhookReconciliationReason::PAID_STATE_INCONSISTENT
                && $status === StripeWebhookReconciliationStatus::MANUAL_REVIEW);
        $this->logger->shouldReceive('warning')->once();
        $this->payments->shouldNotReceive('updateWhere');
        $this->orders->shouldNotReceive('updateFromArray');

        $this->handler->handleEvent(
            $intent,
            'acct_connected',
            'evt_inconsistent',
            'payment_intent.payment_failed',
        );
    }

    public function test_unpaid_order_is_updated_after_lock_and_reconciliation_resolution(): void
    {
        Event::fake();
        $intent = $this->paymentIntent('pi_failed', 'ch_failed');
        $payment = $this->payment(
            OrderStatus::RESERVED->name,
            OrderPaymentStatus::AWAITING_PAYMENT->name,
        );
        $updatedOrder = (new OrderDomainObject)
            ->setId(10)
            ->setStatus(OrderStatus::RESERVED->name)
            ->setPaymentStatus(OrderPaymentStatus::PAYMENT_FAILED->name);

        $this->providerLock->shouldReceive('acquirePaymentIdentity')->once()->with('pi_failed', 'ch_failed');
        $this->payments->shouldReceive('loadRelation')->once()->andReturnSelf();
        $this->payments->shouldReceive('findFirstWhere')->once()->andReturn($payment);
        $this->reconciliations->shouldReceive('resolveExisting')
            ->once()
            ->withArgs(static fn ($dto): bool => $dto->orderId === 10 && $dto->stripePaymentId === 31);
        $this->payments->shouldReceive('updateWhere')
            ->once()
            ->withArgs(static fn (array $attributes, array $where): bool => $attributes['amount_received'] === 0
                && $attributes['charge_id'] === 'ch_failed'
                && $where['payment_intent_id'] === 'pi_failed'
                && $where['order_id'] === 10);
        $this->orders->shouldReceive('loadRelation')->once()->andReturnSelf();
        $this->orders->shouldReceive('updateFromArray')
            ->once()
            ->with(10, ['payment_status' => OrderPaymentStatus::PAYMENT_FAILED->name])
            ->andReturn($updatedOrder);

        $this->handler->handleEvent(
            $intent,
            'acct_connected',
            'evt_failed',
            'payment_intent.payment_failed',
        );

        Event::assertDispatched(OrderStatusChangedEvent::class);
    }

    private function paymentIntent(string $id, ?string $chargeId): PaymentIntent
    {
        return PaymentIntent::constructFrom([
            'id' => $id,
            'status' => 'requires_payment_method',
            'latest_charge' => $chargeId,
            'last_payment_error' => null,
            'payment_method' => null,
            'amount_received' => 0,
            'currency' => 'usd',
        ]);
    }

    private function payment(string $orderStatus, string $paymentStatus): StripePaymentDomainObject
    {
        $order = (new OrderDomainObject)
            ->setId(10)
            ->setStatus($orderStatus)
            ->setPaymentStatus($paymentStatus);

        return (new StripePaymentDomainObject)
            ->setId(31)
            ->setOrderId(10)
            ->setPaymentIntentId('pi_test')
            ->setOrder($order);
    }
}
