<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Order\DTOs;

use HiEvents\DomainObjects\Enums\OrderEffectEmailKind;
use HiEvents\DomainObjects\Enums\OrderEffectType;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;

readonly class ClaimedOrderEffectDTO
{
    public function __construct(
        public int $id,
        public string $deliveryId,
        public int $orderId,
        public OrderEffectType $effectType,
        public string $transitionKey,
        public ?DomainEventType $domainEventType,
        public ?OrderEffectEmailKind $emailKind,
        public string $claimToken,
        public int $attempts,
    ) {}
}
