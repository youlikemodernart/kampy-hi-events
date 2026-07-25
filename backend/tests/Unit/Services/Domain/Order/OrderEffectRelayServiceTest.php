<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Order;

use HiEvents\DomainObjects\Enums\OrderEffectEmailKind;
use HiEvents\DomainObjects\Enums\OrderEffectType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\InvoiceDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderEffectOutboxRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\EventStatistics\EventStatisticsIncrementService;
use HiEvents\Services\Domain\Mail\SendOrderDetailsService;
use HiEvents\Services\Domain\Order\DTOs\ClaimedOrderEffectDTO;
use HiEvents\Services\Domain\Order\OrderEffectRelayService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\Webhook\WebhookDispatchService;
use Illuminate\Database\DatabaseManager;
use Illuminate\Support\Collection;
use LogicException;
use Mockery;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class OrderEffectRelayServiceTest extends TestCase
{
    public function test_webhook_delivery_passes_stable_delivery_id_and_marks_token_fenced_claim(): void
    {
        [$relay, $outbox, $orders, $events, $statistics, $mail, $webhooks, $database, $logger] = $this->mocks();
        $effect = new ClaimedOrderEffectDTO(
            id: 1,
            deliveryId: 'oef_stable',
            orderId: 10,
            effectType: OrderEffectType::WEBHOOK,
            transitionKey: 'FREE_COMPLETED',
            domainEventType: DomainEventType::ORDER_CREATED,
            emailKind: null,
            claimToken: 'claim-one',
            attempts: 1,
        );
        $outbox->shouldReceive('claimBatch')->once()->andReturn(new Collection([$effect]));
        $webhooks->shouldReceive('dispatchOrderWebhook')->once()->with(
            DomainEventType::ORDER_CREATED,
            10,
            'oef_stable',
        );
        $outbox->shouldReceive('markDelivered')->once()->with(1, 'claim-one')->andReturnTrue();

        self::assertSame(1, $relay->processBatch());
    }

    public function test_statistics_and_delivery_marker_share_database_transaction(): void
    {
        [$relay, $outbox, $orders, $events, $statistics, $mail, $webhooks, $database, $logger] = $this->mocks();
        $effect = new ClaimedOrderEffectDTO(
            id: 2,
            deliveryId: 'oef_stats',
            orderId: 11,
            effectType: OrderEffectType::STATISTICS,
            transitionKey: 'STRIPE_COMPLETED',
            domainEventType: null,
            emailKind: null,
            claimToken: 'claim-two',
            attempts: 1,
        );
        $order = (new OrderDomainObject)->setId(11);
        $outbox->shouldReceive('claimBatch')->once()->andReturn(new Collection([$effect]));
        $database->shouldReceive('transaction')->once()->andReturnUsing(static fn ($callback) => $callback());
        $orders->shouldReceive('findById')->once()->with(11)->andReturn($order);
        $statistics->shouldReceive('incrementForOrder')->once()->with($order);
        $outbox->shouldReceive('markDelivered')->once()->with(2, 'claim-two')->andReturnTrue();

        self::assertSame(1, $relay->processBatch());
    }

    public function test_standard_email_delivery_routes_details_and_tickets_then_marks_delivered(): void
    {
        [$relay, $outbox, $orders, $events, $statistics, $mail] = $this->mocks();
        $order = (new OrderDomainObject)->setId(20);
        $effect = $this->emailEffect(3, 20, OrderEffectEmailKind::DETAILS_AND_TICKETS, 'claim-three');
        $outbox->shouldReceive('claimBatch')->once()->andReturn(new Collection([$effect]));
        $orders->shouldReceive('findById')->once()->with(20)->andReturn($order);
        $mail->shouldReceive('sendOrderSummaryAndTicketEmails')->once()->with($order);
        $outbox->shouldReceive('markDelivered')->once()->with(3, 'claim-three')->andReturnTrue();

        self::assertSame(1, $relay->processBatch());
    }

    public function test_customer_summary_email_reloads_invoice_and_event_context_then_marks_delivered(): void
    {
        [$relay, $outbox, $orders, $events, $statistics, $mail] = $this->mocks();
        $initialOrder = (new OrderDomainObject)->setId(21)->setEventId(31);
        $invoice = (new InvoiceDomainObject)->setId(41);
        $reloadedOrder = (new OrderDomainObject)
            ->setId(21)
            ->setEventId(31)
            ->setInvoices(new Collection([$invoice]));
        $organizer = (new OrganizerDomainObject)->setId(51);
        $settings = (new EventSettingDomainObject)->setEventId(31);
        $event = (new EventDomainObject)
            ->setId(31)
            ->setOrganizer($organizer)
            ->setEventSettings($settings);
        $effect = $this->emailEffect(4, 21, OrderEffectEmailKind::CUSTOMER_SUMMARY, 'claim-four');
        $outbox->shouldReceive('claimBatch')->once()->andReturn(new Collection([$effect]));
        $orders->shouldReceive('findById')->once()->with(21)->andReturn($initialOrder);
        $orders->shouldReceive('loadRelation')->times(3)->andReturnSelf();
        $orders->shouldReceive('findById')->once()->with(21)->andReturn($reloadedOrder);
        $events->shouldReceive('loadRelation')->twice()->andReturnSelf();
        $events->shouldReceive('findById')->once()->with(31)->andReturn($event);
        $mail->shouldReceive('sendCustomerOrderSummary')->once()->with(
            $reloadedOrder,
            $event,
            $organizer,
            $settings,
            $invoice,
        );
        $outbox->shouldReceive('markDelivered')->once()->with(4, 'claim-four')->andReturnTrue();

        self::assertSame(1, $relay->processBatch());
    }

    public function test_delivery_exception_records_only_exception_class_for_retry(): void
    {
        [$relay, $outbox, $orders, $events, $statistics, $mail, $webhooks, $database, $logger] = $this->mocks();
        $effect = new ClaimedOrderEffectDTO(
            id: 5,
            deliveryId: 'oef_failure',
            orderId: 22,
            effectType: OrderEffectType::WEBHOOK,
            transitionKey: 'FREE_COMPLETED',
            domainEventType: DomainEventType::ORDER_CREATED,
            emailKind: null,
            claimToken: 'claim-five',
            attempts: 1,
        );
        $outbox->shouldReceive('claimBatch')->once()->andReturn(new Collection([$effect]));
        $webhooks->shouldReceive('dispatchOrderWebhook')->once()->andThrow(new LogicException('private provider detail'));
        $outbox->shouldReceive('markFailed')->once()->with(5, 'claim-five', LogicException::class, 10)->andReturnTrue();
        $logger->shouldReceive('error')->once()->with(
            'Order effect outbox delivery failed',
            Mockery::on(static fn (array $context): bool => $context['exception_class'] === LogicException::class
                && ! array_key_exists('message', $context)
                && ! array_key_exists('exception', $context)),
        );

        self::assertSame(1, $relay->processBatch());
    }

    public function test_statistics_transaction_rolls_back_when_delivery_claim_is_fenced(): void
    {
        [$relay, $outbox, $orders, $events, $statistics, $mail, $webhooks, $database, $logger] = $this->mocks();
        $rolledBack = false;
        $order = (new OrderDomainObject)->setId(23);
        $effect = new ClaimedOrderEffectDTO(
            id: 6,
            deliveryId: 'oef_stats_fenced',
            orderId: 23,
            effectType: OrderEffectType::STATISTICS,
            transitionKey: 'STRIPE_COMPLETED',
            domainEventType: null,
            emailKind: null,
            claimToken: 'claim-six',
            attempts: 1,
        );
        $outbox->shouldReceive('claimBatch')->once()->andReturn(new Collection([$effect]));
        $database->shouldReceive('transaction')->once()->andReturnUsing(
            static function ($callback) use (&$rolledBack) {
                try {
                    return $callback();
                } catch (\Throwable $exception) {
                    $rolledBack = true;
                    throw $exception;
                }
            },
        );
        $orders->shouldReceive('findById')->once()->with(23)->andReturn($order);
        $statistics->shouldReceive('incrementForOrder')->once()->with($order);
        $outbox->shouldReceive('markDelivered')->once()->with(6, 'claim-six')->andReturnFalse();
        $outbox->shouldReceive('markFailed')->once()->with(
            6,
            'claim-six',
            ResourceConflictException::class,
            10,
        )->andReturnFalse();
        $logger->shouldReceive('error')->once();

        self::assertSame(1, $relay->processBatch());
        self::assertTrue($rolledBack);
    }

    private function emailEffect(
        int $id,
        int $orderId,
        OrderEffectEmailKind $emailKind,
        string $claimToken,
    ): ClaimedOrderEffectDTO {
        return new ClaimedOrderEffectDTO(
            id: $id,
            deliveryId: 'oef_email_'.$id,
            orderId: $orderId,
            effectType: OrderEffectType::EMAIL,
            transitionKey: 'OFFLINE_MARKED_PAID',
            domainEventType: null,
            emailKind: $emailKind,
            claimToken: $claimToken,
            attempts: 1,
        );
    }

    private function mocks(): array
    {
        $outbox = Mockery::mock(OrderEffectOutboxRepositoryInterface::class);
        $orders = Mockery::mock(OrderRepositoryInterface::class);
        $events = Mockery::mock(EventRepositoryInterface::class);
        $statistics = Mockery::mock(EventStatisticsIncrementService::class);
        $mail = Mockery::mock(SendOrderDetailsService::class);
        $webhooks = Mockery::mock(WebhookDispatchService::class);
        $database = Mockery::mock(DatabaseManager::class);
        $logger = Mockery::mock(LoggerInterface::class);

        return [
            new OrderEffectRelayService(
                $outbox,
                $orders,
                $events,
                $statistics,
                $mail,
                $webhooks,
                $database,
                $logger,
            ),
            $outbox,
            $orders,
            $events,
            $statistics,
            $mail,
            $webhooks,
            $database,
            $logger,
        ];
    }
}
