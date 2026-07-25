<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\Stripe\DTOs;

use DateTimeImmutable;

readonly class StripeDisputeDTO
{
    public function __construct(
        public string $disputeId,
        public ?int $orderId,
        public ?int $stripePaymentId,
        public ?string $paymentIntentId,
        public ?string $chargeId,
        public ?string $stripeAccountId,
        public int $amountMinor,
        public string $currency,
        public string $status,
        public ?string $reason,
        public array $balanceTransactionIds,
        public ?DateTimeImmutable $evidenceDueAt,
        public ?DateTimeImmutable $closedAt,
        public ?DateTimeImmutable $providerCreatedAt,
        public string $lastEventId,
        public string $lastEventType,
        public DateTimeImmutable $lastEventCreatedAt,
    ) {}
}
