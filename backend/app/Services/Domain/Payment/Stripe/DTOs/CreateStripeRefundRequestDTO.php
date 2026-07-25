<?php

namespace HiEvents\Services\Domain\Payment\Stripe\DTOs;

use HiEvents\DataTransferObjects\BaseDataObject;

class CreateStripeRefundRequestDTO extends BaseDataObject
{
    public function __construct(
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
    ) {}
}
