<?php

namespace HiEvents\Services\Domain\Payment\Stripe\DTOs;

readonly class KampStripeMetadataDTO
{
    public function __construct(
        public array $metadata,
        public string $description,
        public int $ticketQuantity,
    ) {}
}
