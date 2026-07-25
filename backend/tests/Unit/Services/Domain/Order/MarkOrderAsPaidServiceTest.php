<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Order;

use HiEvents\DomainObjects\AccountConfigurationDomainObject;
use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\OrderEffectEmailKind;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\InvoiceRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\Order\MarkOrderAsPaidService;
use HiEvents\Services\Domain\Order\OrderApplicationFeeCalculationService;
use HiEvents\Services\Domain\Order\OrderApplicationFeeService;
use HiEvents\Services\Domain\Order\OrderEffectOutboxService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class MarkOrderAsPaidServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_positive_path_enqueues_exact_effects_and_keeps_status_event_local_only(): void
    {
        Event::fake();
        $orders = Mockery::mock(OrderRepositoryInterface::class);
        $database = Mockery::mock(DatabaseManager::class);
        $affiliates = Mockery::mock(AffiliateRepositoryInterface::class);
        $invoices = Mockery::mock(InvoiceRepositoryInterface::class);
        $attendees = Mockery::mock(AttendeeRepositoryInterface::class);
        $feeCalculation = Mockery::mock(OrderApplicationFeeCalculationService::class);
        $events = Mockery::mock(EventRepositoryInterface::class);
        $applicationFees = Mockery::mock(OrderApplicationFeeService::class);
        $outbox = Mockery::mock(OrderEffectOutboxService::class);
        $service = new MarkOrderAsPaidService(
            $orders,
            $database,
            $affiliates,
            $invoices,
            $attendees,
            $feeCalculation,
            $events,
            $applicationFees,
            $outbox,
        );
        $pendingOrder = (new OrderDomainObject)
            ->setId(10)
            ->setEventId(20)
            ->setStatus(OrderStatus::AWAITING_OFFLINE_PAYMENT->name)
            ->setPaymentStatus(OrderPaymentStatus::AWAITING_OFFLINE_PAYMENT->name)
            ->setCurrency('USD');
        $paidOrder = (new OrderDomainObject)
            ->setId(10)
            ->setEventId(20)
            ->setStatus(OrderStatus::COMPLETED->name)
            ->setPaymentStatus(OrderPaymentStatus::PAYMENT_RECEIVED->name)
            ->setCurrency('USD');
        $configuration = new AccountConfigurationDomainObject;
        $account = new AccountDomainObject;
        $account->setConfiguration($configuration);
        $event = (new EventDomainObject)->setId(20)->setAccount($account);

        $database->shouldReceive('transaction')->once()->andReturnUsing(static fn ($callback) => $callback());
        $orders->shouldReceive('loadRelation')->times(4)->andReturnSelf();
        $orders->shouldReceive('findFirstWhere')->once()->andReturn($pendingOrder);
        $orders->shouldReceive('updateFromArray')->once();
        $invoices->shouldReceive('findLatestInvoiceForOrder')->once()->with(10)->andReturnNull();
        $orders->shouldReceive('findById')->once()->with(10)->andReturn($paidOrder);
        $affiliates->shouldNotReceive('incrementSales');
        $attendees->shouldReceive('updateWhere')->once();
        $events->shouldReceive('loadRelation')->once()->andReturnSelf();
        $events->shouldReceive('findById')->once()->with(20)->andReturn($event);
        $feeCalculation->shouldReceive('calculateApplicationFee')->once()->andReturnNull();
        $applicationFees->shouldReceive('createOrderApplicationFee')->once();
        $outbox->shouldReceive('enqueueCompletedOrder')->once()->with(
            10,
            OrderEffectOutboxService::TRANSITION_OFFLINE_MARKED_PAID,
            DomainEventType::ORDER_MARKED_AS_PAID,
            OrderEffectEmailKind::CUSTOMER_SUMMARY,
        );

        $result = $service->markOrderAsPaid(10, 20);

        self::assertSame($paidOrder, $result);
        Event::assertDispatched(
            OrderStatusChangedEvent::class,
            static fn (OrderStatusChangedEvent $event): bool => $event->order === $paidOrder
                && ! $event->sendEmails
                && ! $event->createInvoice
                && ! $event->updateStatistics,
        );
    }
}
