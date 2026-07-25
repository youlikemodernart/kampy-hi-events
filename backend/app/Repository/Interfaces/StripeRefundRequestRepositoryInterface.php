<?php

namespace HiEvents\Repository\Interfaces;

use HiEvents\Services\Domain\Payment\Stripe\DTOs\CreateStripeRefundRequestDTO;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\StripeRefundRequestClaimDTO;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\StripeRefundRequestRecordDTO;

interface StripeRefundRequestRepositoryInterface
{
    public function claimOrLoad(CreateStripeRefundRequestDTO $request): StripeRefundRequestClaimDTO;

    public function findByRequestId(string $requestId, bool $forUpdate = false): ?StripeRefundRequestRecordDTO;

    public function recordAttempt(string $requestId): void;

    public function recordProviderError(string $requestId, string $errorClass): void;

    public function recordProviderResult(
        string $requestId,
        string $providerRefundId,
        string $providerStatus,
        bool $terminal = false,
    ): StripeRefundRequestRecordDTO;

    public function markCancelApplied(string $requestId): void;

    public function claimNotification(string $requestId): bool;

    public function markNotificationSent(string $requestId): void;

    public function releaseNotificationClaim(string $requestId): void;
}
