<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Application\Handlers\Order;

use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\TransitionOrderToOfflinePaymentPublicDTO;
use HiEvents\Services\Application\Handlers\Order\TransitionOrderToOfflinePaymentHandler;
use HiEvents\Services\Domain\Order\OrderEffectOutboxService;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Facades\Event;
use Mockery;
use Tests\TestCase;

class TransitionOrderToOfflinePaymentHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Carbon::setTestNow();
        Mockery::close();
        parent::tearDown();
    }

    public function test_positive_path_enqueues_exact_offline_effects_and_preserves_status_event_semantics(): void
    {
        Event::fake();
        Carbon::setTestNow('2026-07-25 12:00:00');
        $quantities = Mockery::mock(ProductQuantityUpdateService::class);
        $orders = Mockery::mock(OrderRepositoryInterface::class);
        $database = Mockery::mock(DatabaseManager::class);
        $settingsRepository = Mockery::mock(EventSettingsRepositoryInterface::class);
        $sessions = Mockery::mock(CheckoutSessionManagementService::class);
        $outbox = Mockery::mock(OrderEffectOutboxService::class);
        $handler = new TransitionOrderToOfflinePaymentHandler(
            $quantities,
            $orders,
            $database,
            $settingsRepository,
            $sessions,
            $outbox,
        );
        $reservedOrder = (new OrderDomainObject)
            ->setId(10)
            ->setEventId(20)
            ->setShortId('ord_short')
            ->setSessionId('session-id')
            ->setStatus(OrderStatus::RESERVED->name)
            ->setPaymentStatus(OrderPaymentStatus::AWAITING_PAYMENT->name)
            ->setReservedUntil(now()->addHour()->toDateTimeString());
        $offlineOrder = (new OrderDomainObject)
            ->setId(10)
            ->setEventId(20)
            ->setStatus(OrderStatus::AWAITING_OFFLINE_PAYMENT->name)
            ->setPaymentStatus(OrderPaymentStatus::AWAITING_OFFLINE_PAYMENT->name);
        $settings = (new EventSettingDomainObject)
            ->setPaymentProviders([PaymentProviders::OFFLINE->value])
            ->setEnableInvoicing(true);

        $database->shouldReceive('transaction')->once()->andReturnUsing(static fn ($callback) => $callback());
        $orders->shouldReceive('loadRelation')->twice()->andReturnSelf();
        $orders->shouldReceive('findByShortId')->once()->with('ord_short')->andReturn($reservedOrder);
        $sessions->shouldReceive('verifySession')->once()->with('session-id')->andReturnTrue();
        $settingsRepository->shouldReceive('findFirstWhere')->once()->with(['event_id' => 20])->andReturn($settings);
        $orders->shouldReceive('updateFromArray')->once();
        $quantities->shouldReceive('updateQuantitiesFromOrder')->once()->with($reservedOrder);
        $orders->shouldReceive('findById')->once()->with(10)->andReturn($offlineOrder);
        $outbox->shouldReceive('enqueueOfflineSubmission')->once()->with(10);

        $result = $handler->handle(new TransitionOrderToOfflinePaymentPublicDTO('ord_short'));

        self::assertSame($offlineOrder, $result);
        Event::assertDispatched(
            OrderStatusChangedEvent::class,
            static fn (OrderStatusChangedEvent $event): bool => $event->order === $offlineOrder
                && ! $event->sendEmails
                && $event->createInvoice
                && $event->updateStatistics,
        );
    }
}
