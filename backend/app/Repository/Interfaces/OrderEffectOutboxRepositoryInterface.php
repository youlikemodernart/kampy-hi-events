<?php

declare(strict_types=1);

namespace HiEvents\Repository\Interfaces;

use DateTimeInterface;
use HiEvents\Services\Domain\Order\DTOs\ClaimedOrderEffectDTO;
use HiEvents\Services\Domain\Order\DTOs\OrderEffectRequestDTO;
use Illuminate\Support\Collection;

interface OrderEffectOutboxRepositoryInterface
{
    public function enqueue(int $orderId, string $transitionKey, OrderEffectRequestDTO $effect): string;

    /** @return Collection<int, ClaimedOrderEffectDTO> */
    public function claimBatch(int $limit, DateTimeInterface $staleBefore): Collection;

    public function markDelivered(int $id, string $claimToken): bool;

    public function markFailed(int $id, string $claimToken, string $errorClass, int $maxAttempts): bool;
}
