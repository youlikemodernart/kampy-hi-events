<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Order;

use HiEvents\DomainObjects\Enums\OrderEffectEmailKind;
use HiEvents\DomainObjects\Enums\OrderEffectType;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\OrderEffectOutboxRepositoryInterface;
use HiEvents\Services\Domain\Order\DTOs\OrderEffectRequestDTO;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use Illuminate\Database\DatabaseManager;

class OrderEffectOutboxService
{
    public const TRANSITION_FREE_COMPLETED = 'FREE_COMPLETED';

    public const TRANSITION_STRIPE_COMPLETED = 'STRIPE_COMPLETED';

    public const TRANSITION_OFFLINE_SUBMITTED = 'OFFLINE_SUBMITTED';

    public const TRANSITION_OFFLINE_MARKED_PAID = 'OFFLINE_MARKED_PAID';

    public function __construct(
        private readonly OrderEffectOutboxRepositoryInterface $repository,
        private readonly DatabaseManager $databaseManager,
    ) {}

    public function enqueueCompletedOrder(
        int $orderId,
        string $transitionKey,
        DomainEventType $webhookEventType,
        OrderEffectEmailKind $emailKind = OrderEffectEmailKind::DETAILS_AND_TICKETS,
    ): void {
        $this->requireTransaction();
        $this->repository->enqueue($orderId, $transitionKey, new OrderEffectRequestDTO(OrderEffectType::STATISTICS));
        $this->repository->enqueue(
            $orderId,
            $transitionKey,
            new OrderEffectRequestDTO(OrderEffectType::EMAIL, emailKind: $emailKind),
        );
        $this->repository->enqueue(
            $orderId,
            $transitionKey,
            new OrderEffectRequestDTO(OrderEffectType::WEBHOOK, domainEventType: $webhookEventType),
        );
    }

    public function enqueueOfflineSubmission(int $orderId): void
    {
        $this->requireTransaction();
        $this->repository->enqueue(
            $orderId,
            self::TRANSITION_OFFLINE_SUBMITTED,
            new OrderEffectRequestDTO(
                OrderEffectType::EMAIL,
                emailKind: OrderEffectEmailKind::DETAILS_AND_TICKETS,
            ),
        );
        $this->repository->enqueue(
            $orderId,
            self::TRANSITION_OFFLINE_SUBMITTED,
            new OrderEffectRequestDTO(OrderEffectType::WEBHOOK, domainEventType: DomainEventType::ORDER_CREATED),
        );
    }

    private function requireTransaction(): void
    {
        if ($this->databaseManager->connection()->transactionLevel() < 1) {
            throw new ResourceConflictException(__('Order effects must be recorded within the owning database transaction.'));
        }
    }
}
