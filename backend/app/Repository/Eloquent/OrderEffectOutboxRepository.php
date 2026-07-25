<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use DateTimeInterface;
use HiEvents\DomainObjects\Enums\OrderEffectEmailKind;
use HiEvents\DomainObjects\Enums\OrderEffectStatus;
use HiEvents\DomainObjects\Enums\OrderEffectType;
use HiEvents\Models\OrderEffectOutbox;
use HiEvents\Repository\Interfaces\OrderEffectOutboxRepositoryInterface;
use HiEvents\Services\Domain\Order\DTOs\ClaimedOrderEffectDTO;
use HiEvents\Services\Domain\Order\DTOs\OrderEffectRequestDTO;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class OrderEffectOutboxRepository implements OrderEffectOutboxRepositoryInterface
{
    public function enqueue(int $orderId, string $transitionKey, OrderEffectRequestDTO $effect): string
    {
        $this->validateEffect($effect);
        $businessKey = implode(':', array_filter([
            'order-effect-v1',
            (string) $orderId,
            $transitionKey,
            $effect->effectType->value,
            $effect->domainEventType?->value,
            $effect->emailKind?->value,
        ], static fn (?string $value): bool => $value !== null));
        $deliveryId = 'oef_'.substr(hash('sha256', $businessKey), 0, 40);
        $now = now();

        OrderEffectOutbox::query()->insertOrIgnore([
            'delivery_id' => $deliveryId,
            'business_key' => $businessKey,
            'order_id' => $orderId,
            'effect_type' => $effect->effectType->value,
            'transition_key' => $transitionKey,
            'domain_event_type' => $effect->domainEventType?->value,
            'email_kind' => $effect->emailKind?->value,
            'status' => OrderEffectStatus::PENDING->value,
            'attempts' => 0,
            'available_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        return $deliveryId;
    }

    public function claimBatch(int $limit, DateTimeInterface $staleBefore): Collection
    {
        if ($limit < 1 || $limit > 100) {
            throw new InvalidArgumentException('Order effect outbox claim batch size must be between 1 and 100.');
        }

        return DB::transaction(function () use ($limit, $staleBefore): Collection {
            $now = now();
            $query = OrderEffectOutbox::query()
                ->where(static function (Builder $query) use ($now, $staleBefore): void {
                    $query->where(static function (Builder $query) use ($now): void {
                        $query->whereIn('status', [
                            OrderEffectStatus::PENDING->value,
                            OrderEffectStatus::RETRYABLE->value,
                        ])->where('available_at', '<=', $now);
                    })->orWhere(static function (Builder $query) use ($staleBefore): void {
                        $query->where('status', OrderEffectStatus::PROCESSING->value)
                            ->where('claimed_at', '<=', $staleBefore);
                    });
                })
                ->orderBy('id')
                ->limit($limit);

            if (DB::connection()->getDriverName() === 'pgsql') {
                $query->lock('FOR UPDATE SKIP LOCKED');
            } else {
                $query->lockForUpdate();
            }

            $rows = $query->get();

            return $rows->map(function (OrderEffectOutbox $row) use ($now): ClaimedOrderEffectDTO {
                $claimToken = (string) Str::uuid();
                OrderEffectOutbox::query()->whereKey($row->getKey())->update([
                    'status' => OrderEffectStatus::PROCESSING->value,
                    'claim_token' => $claimToken,
                    'claimed_at' => $now,
                    'attempts' => DB::raw('attempts + 1'),
                    'last_error_class' => null,
                    'updated_at' => $now,
                ]);

                return new ClaimedOrderEffectDTO(
                    id: (int) $row->getKey(),
                    deliveryId: $row->delivery_id,
                    orderId: $row->order_id,
                    effectType: OrderEffectType::from($row->effect_type),
                    transitionKey: $row->transition_key,
                    domainEventType: $row->domain_event_type === null
                        ? null
                        : DomainEventType::from($row->domain_event_type),
                    emailKind: $row->email_kind === null
                        ? null
                        : OrderEffectEmailKind::from($row->email_kind),
                    claimToken: $claimToken,
                    attempts: $row->attempts + 1,
                );
            })->values();
        });
    }

    public function markDelivered(int $id, string $claimToken): bool
    {
        $now = now();

        return OrderEffectOutbox::query()
            ->whereKey($id)
            ->where('status', OrderEffectStatus::PROCESSING->value)
            ->where('claim_token', $claimToken)
            ->update([
                'status' => OrderEffectStatus::DELIVERED->value,
                'delivered_at' => $now,
                'claimed_at' => null,
                'claim_token' => null,
                'last_error_class' => null,
                'updated_at' => $now,
            ]) === 1;
    }

    public function markFailed(int $id, string $claimToken, string $errorClass, int $maxAttempts): bool
    {
        if ($maxAttempts < 1) {
            throw new InvalidArgumentException('Order effect outbox maximum attempts must be positive.');
        }

        return DB::transaction(function () use ($id, $claimToken, $errorClass, $maxAttempts): bool {
            $row = OrderEffectOutbox::query()
                ->whereKey($id)
                ->where('status', OrderEffectStatus::PROCESSING->value)
                ->where('claim_token', $claimToken)
                ->lockForUpdate()
                ->first();

            if ($row === null) {
                return false;
            }

            $manualReview = $row->attempts >= $maxAttempts;
            $now = now();
            $delaySeconds = min(3600, 15 * (2 ** min($row->attempts, 8)));

            return OrderEffectOutbox::query()
                ->whereKey($id)
                ->where('claim_token', $claimToken)
                ->update([
                    'status' => $manualReview
                        ? OrderEffectStatus::MANUAL_REVIEW->value
                        : OrderEffectStatus::RETRYABLE->value,
                    'available_at' => $manualReview ? $row->available_at : $now->copy()->addSeconds($delaySeconds),
                    'claimed_at' => null,
                    'claim_token' => null,
                    'manual_review_at' => $manualReview ? $now : null,
                    'last_error_class' => substr($errorClass, 0, 255),
                    'updated_at' => $now,
                ]) === 1;
        });
    }

    private function validateEffect(OrderEffectRequestDTO $effect): void
    {
        if ($effect->effectType === OrderEffectType::WEBHOOK && $effect->domainEventType === null) {
            throw new InvalidArgumentException('Order webhook outbox effects require a domain event type.');
        }

        if ($effect->effectType !== OrderEffectType::WEBHOOK && $effect->domainEventType !== null) {
            throw new InvalidArgumentException('Only order webhook outbox effects may contain a domain event type.');
        }

        if ($effect->effectType === OrderEffectType::EMAIL && $effect->emailKind === null) {
            throw new InvalidArgumentException('Order email outbox effects require an email kind.');
        }

        if ($effect->effectType !== OrderEffectType::EMAIL && $effect->emailKind !== null) {
            throw new InvalidArgumentException('Only order email outbox effects may contain an email kind.');
        }
    }
}
