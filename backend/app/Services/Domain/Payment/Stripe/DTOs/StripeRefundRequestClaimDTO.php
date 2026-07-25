<?php

namespace HiEvents\Services\Domain\Payment\Stripe\DTOs;

use HiEvents\DataTransferObjects\BaseDataObject;

class StripeRefundRequestClaimDTO extends BaseDataObject
{
    public function __construct(
        public readonly StripeRefundRequestRecordDTO $request,
        public readonly bool $created,
    ) {}
}
