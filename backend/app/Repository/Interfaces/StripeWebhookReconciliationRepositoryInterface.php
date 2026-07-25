<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use DateTimeInterface;
use HiEvents\DomainObjects\Enums\StripeWebhookReconciliationStatus;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\StripeWebhookReconciliationDTO;

interface StripeWebhookReconciliationRepositoryInterface
{
    public function recordPending(
        StripeWebhookReconciliationDTO $reconciliation,
        string $errorClass,
    ): void;

    public function resolveExisting(StripeWebhookReconciliationDTO $reconciliation): void;

    public function recordAudit(
        StripeWebhookReconciliationDTO $reconciliation,
        StripeWebhookReconciliationStatus $status,
    ): void;

    public function agePendingBefore(DateTimeInterface $cutoff, int $limit): int;
}
