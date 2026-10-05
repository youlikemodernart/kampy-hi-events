<?php

namespace HiEvents\Services\Domain\Registration\DTO;

use HiEvents\DataTransferObjects\BaseDataObject;

final class RespondentChallengeDelivery extends BaseDataObject
{
    public function __construct(public readonly string $email, public readonly string $token) {}
}
