<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use HiEvents\Services\Domain\Payment\Stripe\DTOs\StripeDisputeDTO;

interface StripeDisputeRepositoryInterface
{
    public function upsert(StripeDisputeDTO $dispute): void;

    public function linkPendingToPayment(
        int $orderId,
        int $stripePaymentId,
        string $paymentIntentId,
        ?string $chargeId,
        ?string $stripeAccountId,
    ): int;
}
