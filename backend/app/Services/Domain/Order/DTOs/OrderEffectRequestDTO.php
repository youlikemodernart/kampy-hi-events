<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Order\DTOs;

use HiEvents\DomainObjects\Enums\OrderEffectEmailKind;
use HiEvents\DomainObjects\Enums\OrderEffectType;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;

readonly class OrderEffectRequestDTO
{
    public function __construct(
        public OrderEffectType $effectType,
        public ?DomainEventType $domainEventType = null,
        public ?OrderEffectEmailKind $emailKind = null,
    ) {}
}
