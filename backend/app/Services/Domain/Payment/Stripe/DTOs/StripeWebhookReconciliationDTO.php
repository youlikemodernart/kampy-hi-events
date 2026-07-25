<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\Stripe\DTOs;

use HiEvents\DomainObjects\Enums\StripeWebhookReconciliationReason;

readonly class StripeWebhookReconciliationDTO
{
    public function __construct(
        public string $eventId,
        public string $eventType,
        public ?string $stripeAccountId,
        public string $providerObjectType,
        public string $providerObjectId,
        public ?string $paymentIntentId,
        public ?string $chargeId,
        public ?string $refundId,
        public ?int $orderId,
        public ?int $stripePaymentId,
        public StripeWebhookReconciliationReason $reason,
    ) {}
}
