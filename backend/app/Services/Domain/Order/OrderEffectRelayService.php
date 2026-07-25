<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Order;

use HiEvents\DomainObjects\AttendeeDomainObject;
use HiEvents\DomainObjects\Enums\OrderEffectEmailKind;
use HiEvents\DomainObjects\Enums\OrderEffectType;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\InvoiceDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderEffectOutboxRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Domain\EventStatistics\EventStatisticsIncrementService;
use HiEvents\Services\Domain\Mail\SendOrderDetailsService;
use HiEvents\Services\Domain\Order\DTOs\ClaimedOrderEffectDTO;
use HiEvents\Services\Infrastructure\Webhook\WebhookDispatchService;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Throwable;

class OrderEffectRelayService
{
    public function __construct(
        private readonly OrderEffectOutboxRepositoryInterface $outboxRepository,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly EventStatisticsIncrementService $statisticsService,
        private readonly SendOrderDetailsService $sendOrderDetailsService,
        private readonly WebhookDispatchService $webhookDispatchService,
        private readonly DatabaseManager $databaseManager,
        private readonly LoggerInterface $logger,
    ) {}

    public function processBatch(int $limit = 25): int
    {
        $effects = $this->outboxRepository->claimBatch($limit, now()->subMinutes(15));
        $maxAttempts = max(1, (int) config('services.order_effect_outbox.max_attempts', 10));

        foreach ($effects as $effect) {
            try {
                $this->deliver($effect);
            } catch (Throwable $exception) {
                $this->outboxRepository->markFailed(
                    $effect->id,
                    $effect->claimToken,
                    $exception::class,
                    $maxAttempts,
                );
                $this->logger->error('Order effect outbox delivery failed', [
                    'delivery_id' => $effect->deliveryId,
                    'effect_type' => $effect->effectType->value,
                    'order_id' => $effect->orderId,
                    'exception_class' => $exception::class,
                ]);
            }
        }

        return $effects->count();
    }

    private function deliver(ClaimedOrderEffectDTO $effect): void
    {
        if ($effect->effectType === OrderEffectType::STATISTICS) {
            $this->databaseManager->transaction(function () use ($effect): void {
                $this->statisticsService->incrementForOrder($this->findOrder($effect->orderId));
                $this->requireDeliveredClaim($effect);
            });

            return;
        }

        if ($effect->effectType === OrderEffectType::EMAIL) {
            $this->deliverEmail($effect);
            $this->requireDeliveredClaim($effect);

            return;
        }

        if ($effect->effectType === OrderEffectType::WEBHOOK && $effect->domainEventType !== null) {
            $this->webhookDispatchService->dispatchOrderWebhook(
                $effect->domainEventType,
                $effect->orderId,
                $effect->deliveryId,
            );
            $this->requireDeliveredClaim($effect);

            return;
        }

        throw new ResourceConflictException(__('Order effect outbox row has an invalid effect contract.'));
    }

    private function deliverEmail(ClaimedOrderEffectDTO $effect): void
    {
        $order = $this->findOrder($effect->orderId);

        if ($effect->emailKind === OrderEffectEmailKind::DETAILS_AND_TICKETS) {
            $this->sendOrderDetailsService->sendOrderSummaryAndTicketEmails($order);

            return;
        }

        if ($effect->emailKind !== OrderEffectEmailKind::CUSTOMER_SUMMARY) {
            throw new ResourceConflictException(__('Order email outbox row has an invalid email kind.'));
        }

        $order = $this->orderRepository
            ->loadRelation(OrderItemDomainObject::class)
            ->loadRelation(AttendeeDomainObject::class)
            ->loadRelation(InvoiceDomainObject::class)
            ->findById($effect->orderId);
        $event = $this->eventRepository
            ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
            ->loadRelation(new Relationship(EventSettingDomainObject::class))
            ->findById($order->getEventId());

        $this->sendOrderDetailsService->sendCustomerOrderSummary(
            order: $order,
            event: $event,
            organizer: $event->getOrganizer(),
            eventSettings: $event->getEventSettings(),
            invoice: $order->getLatestInvoice(),
        );
    }

    private function findOrder(int $orderId): OrderDomainObject
    {
        return $this->orderRepository->findById($orderId);
    }

    private function requireDeliveredClaim(ClaimedOrderEffectDTO $effect): void
    {
        if (! $this->outboxRepository->markDelivered($effect->id, $effect->claimToken)) {
            throw new ResourceConflictException(__('Order effect outbox claim is no longer active.'));
        }
    }
}
