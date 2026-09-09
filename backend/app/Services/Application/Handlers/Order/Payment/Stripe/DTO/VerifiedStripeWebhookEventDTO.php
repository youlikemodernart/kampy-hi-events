<?php

declare(strict_types=1);

namespace HiEvents\Services\Application\Handlers\Order\Payment\Stripe\DTO;

use Stripe\Event;

readonly class VerifiedStripeWebhookEventDTO
{
    public function __construct(
        public Event $event,
        /** @var list<string> */
        public array $signingPlatforms,
    ) {}
}
