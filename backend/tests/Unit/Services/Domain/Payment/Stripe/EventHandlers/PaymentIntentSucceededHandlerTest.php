<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Payment\Stripe\EventHandlers;

use Carbon\Carbon;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Repository\Eloquent\StripePaymentsRepository;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\StripeDisputeRepositoryInterface;
use HiEvents\Services\Domain\Order\OrderApplicationFeeService;
use HiEvents\Services\Domain\Order\OrderEffectOutboxService;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\PaymentIntentSucceededHandler;
use HiEvents\Services\Domain\Payment\Stripe\StripeProviderObjectLockService;
use HiEvents\Services\Domain\Payment\Stripe\StripeRefundExpiredOrderService;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stripe\PaymentIntent;
use Tests\TestCase;

class PaymentIntentSucceededHandlerTest extends TestCase
{
    private OrderRepositoryInterface|MockInterface $orders;

    private StripePaymentsRepository|MockInterface $payments;

    private AffiliateRepositoryInterface|MockInterface $affiliates;

    private ProductQuantityUpdateService|MockInterface $quantities;

    private StripeRefundExpiredOrderService $expiredOrders;

    private AttendeeRepositoryInterface|MockInterface $attendees;

    private DatabaseManager|MockInterface $database;

    private LoggerInterface|MockInterface $logger;

    private OrderEffectOutboxService|MockInterface $outbox;

    private OrderApplicationFeeService|MockInterface $applicationFees;

    private EventSettingsRepositoryInterface|MockInterface $eventSettings;

    private StripeDisputeRepositoryInterface|MockInterface $disputes;

    private StripeProviderObjectLockService|MockInterface $providerObjectLock;

    private PaymentIntentSucceededHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orders = Mockery::mock(OrderRepositoryInterface::class);
        $this->payments = Mockery::mock(StripePaymentsRepository::class);
        $this->affiliates = Mockery::mock(AffiliateRepositoryInterface::class);
        $this->quantities = Mockery::mock(ProductQuantityUpdateService::class);
        $this->expiredOrders = (new \ReflectionClass(StripeRefundExpiredOrderService::class))->newInstanceWithoutConstructor();
        $this->attendees = Mockery::mock(AttendeeRepositoryInterface::class);
        $this->database = Mockery::mock(DatabaseManager::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->outbox = Mockery::mock(OrderEffectOutboxService::class);
        $this->applicationFees = Mockery::mock(OrderApplicationFeeService::class);
        $this->eventSettings = Mockery::mock(EventSettingsRepositoryInterface::class);
        $this->disputes = Mockery::mock(StripeDisputeRepositoryInterface::class);
        $this->providerObjectLock = Mockery::mock(StripeProviderObjectLockService::class);

        $this->handler = new PaymentIntentSucceededHandler(
            $this->orders,
            $this->payments,
            $this->affiliates,
            $this->quantities,
            $this->expiredOrders,
            $this->attendees,
            $this->database,
            $this->logger,
            $this->applicationFees,
            $this->eventSettings,
            $this->disputes,
            $this->providerObjectLock,
            $this->outbox,
        );
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_positive_path_records_exact_outbox_effects_and_preserves_suppressed_status_event(): void
    {
        Event::fake();
        Carbon::setTestNow('2026-07-25 12:00:00');
        $pendingOrder = (new OrderDomainObject)
            ->setId(10)
            ->setEventId(20)
            ->setStatus(OrderStatus::RESERVED->name)
            ->setPaymentStatus(OrderPaymentStatus::AWAITING_PAYMENT->name)
            ->setReservedUntil(now()->addHour()->toDateTimeString())
            ->setCurrency('USD');
        $updatedOrder = (new OrderDomainObject)
            ->setId(10)
            ->setEventId(20)
            ->setStatus(OrderStatus::COMPLETED->name)
            ->setPaymentStatus(OrderPaymentStatus::PAYMENT_RECEIVED->name)
            ->setCurrency('USD');
        $stripePayment = (new StripePaymentDomainObject)
            ->setId(31)
            ->setOrderId(10)
            ->setPaymentIntentId('pi_positive')
            ->setConnectedAccountId('acct_connected')
            ->setOrder($pendingOrder);
        $paymentIntent = PaymentIntent::constructFrom([
            'id' => 'pi_positive',
            'status' => 'succeeded',
            'latest_charge' => 'ch_positive',
            'payment_method' => 'pm_positive',
            'last_payment_error' => null,
            'amount_received' => 5695,
            'application_fee_amount' => 600,
            'currency' => 'usd',
        ]);
        $settings = (new EventSettingDomainObject)->setEnableInvoicing(true);

        $this->database->shouldReceive('transaction')->once()->andReturnUsing(static fn ($callback) => $callback());
        $this->providerObjectLock->shouldReceive('acquirePaymentIdentity')->once()->with('pi_positive', 'ch_positive');
        $this->payments->shouldReceive('loadRelation')->once()->andReturnSelf();
        $this->payments->shouldReceive('findFirstWhere')->once()->andReturn($stripePayment);
        $this->payments->shouldReceive('updateWhere')->once();
        $this->disputes->shouldReceive('linkPendingToPayment')->once()->with(
            10,
            31,
            'pi_positive',
            'ch_positive',
            'acct_connected',
        )->andReturn(0);
        $this->orders->shouldReceive('loadRelation')->once()->andReturnSelf();
        $this->orders->shouldReceive('updateFromArray')->once()->andReturn($updatedOrder);
        $this->attendees->shouldReceive('updateWhere')->once();
        $this->quantities->shouldReceive('updateQuantitiesFromOrder')->once()->with($updatedOrder);
        $this->eventSettings->shouldReceive('findFirstWhere')->once()->with(['event_id' => 20])->andReturn($settings);
        $this->outbox->shouldReceive('enqueueCompletedOrder')->once()->with(
            10,
            OrderEffectOutboxService::TRANSITION_STRIPE_COMPLETED,
            DomainEventType::ORDER_CREATED,
        );
        $this->applicationFees->shouldReceive('createOrderApplicationFee')->once();
        $this->logger->shouldReceive('info')->once()->with(
            'Stripe payment intent succeeded event handled',
            Mockery::type('array'),
        );

        $this->handler->handleEvent($paymentIntent);

        Event::assertDispatched(
            OrderStatusChangedEvent::class,
            static fn (OrderStatusChangedEvent $event): bool => $event->order === $updatedOrder
                && ! $event->sendEmails
                && $event->createInvoice
                && ! $event->updateStatistics,
        );
    }

    public function test_retry_after_committed_business_transaction_is_durably_idempotent(): void
    {
        $order = (new OrderDomainObject)
            ->setId(10)
            ->setStatus(OrderStatus::COMPLETED->name)
            ->setPaymentStatus(OrderPaymentStatus::PAYMENT_RECEIVED->name);
        $stripePayment = (new StripePaymentDomainObject)
            ->setId(31)
            ->setOrderId(10)
            ->setPaymentIntentId('pi_committed')
            ->setChargeId('ch_committed')
            ->setOrder($order);
        $paymentIntent = PaymentIntent::constructFrom([
            'id' => 'pi_committed',
            'status' => 'succeeded',
            'latest_charge' => 'ch_committed',
            'amount_received' => 5695,
            'currency' => 'usd',
        ]);

        $this->database->shouldReceive('transaction')->once()->andReturnUsing(static fn ($callback) => $callback());
        $this->providerObjectLock->shouldReceive('acquirePaymentIdentity')->once()->with('pi_committed', 'ch_committed');
        $this->payments->shouldReceive('loadRelation')->once()->andReturnSelf();
        $this->payments->shouldReceive('findFirstWhere')->once()->andReturn($stripePayment);
        $this->disputes->shouldReceive('linkPendingToPayment')->once()->with(10, 31, 'pi_committed', 'ch_committed', null)->andReturn(1);
        $this->logger->shouldReceive('info')->once()->with('Stripe payment intent succeeded event handled', Mockery::type('array'));

        $this->handler->handleEvent($paymentIntent);

        self::assertTrue(true);
    }

    public function test_missing_local_payment_fails_for_provider_retry_instead_of_acknowledging(): void
    {
        $paymentIntent = PaymentIntent::constructFrom([
            'id' => 'pi_not_local_yet',
            'status' => 'succeeded',
            'latest_charge' => 'ch_not_local_yet',
            'amount_received' => 5695,
            'currency' => 'usd',
        ]);

        $this->database->shouldReceive('transaction')->once()->andReturnUsing(static fn ($callback) => $callback());
        $this->providerObjectLock->shouldReceive('acquirePaymentIdentity')->once()->with('pi_not_local_yet', 'ch_not_local_yet');
        $this->payments->shouldReceive('loadRelation')->once()->andReturnSelf();
        $this->payments->shouldReceive('findFirstWhere')->once()->andReturnNull();
        $this->logger->shouldReceive('error')->once()->with(
            'Payment intent not found when handling payment intent succeeded event',
            Mockery::type('array'),
        );
        $this->disputes->shouldNotReceive('linkPendingToPayment');

        $this->expectException(RuntimeException::class);

        $this->handler->handleEvent($paymentIntent);
    }
}
