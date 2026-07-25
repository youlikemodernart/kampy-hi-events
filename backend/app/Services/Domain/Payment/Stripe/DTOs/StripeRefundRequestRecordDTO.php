<?php

namespace HiEvents\Services\Domain\Payment\Stripe\DTOs;

use HiEvents\DataTransferObjects\BaseDataObject;

class StripeRefundRequestRecordDTO extends BaseDataObject
{
    public function __construct(
        public readonly int $id,
        public readonly string $requestId,
        public readonly int $orderId,
        public readonly int $stripePaymentId,
        public readonly string $paymentIntentId,
        public readonly ?string $stripeAccountId,
        public readonly int $amountMinor,
        public readonly string $currency,
        public readonly bool $notifyBuyer,
        public readonly bool $cancelOrder,
        public readonly ?bool $refundApplicationFee,
        public readonly string $status,
        public readonly int $attempts,
        public readonly ?string $providerRefundId,
        public readonly ?string $providerStatus,
        public readonly bool $cancelApplied,
        public readonly bool $notificationClaimed,
        public readonly bool $notificationSent,
    ) {}
}
