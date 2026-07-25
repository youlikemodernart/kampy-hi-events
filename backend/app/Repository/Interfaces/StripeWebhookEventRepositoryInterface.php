<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

interface StripeWebhookEventRepositoryInterface
{
    public function claim(string $eventId, string $eventType, ?string $stripeAccountId): ?string;

    public function markHandled(string $eventId, string $claimToken): void;

    public function markFailed(string $eventId, string $claimToken, string $errorClass): void;
}
