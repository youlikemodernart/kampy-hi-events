<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use DateTimeInterface;
use HiEvents\DomainObjects\Enums\StripeWebhookReconciliationStatus;
use HiEvents\Models\StripeWebhookReconciliation;
use HiEvents\Repository\Interfaces\StripeWebhookReconciliationRepositoryInterface;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\StripeWebhookReconciliationDTO;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

class StripeWebhookReconciliationRepository implements StripeWebhookReconciliationRepositoryInterface
{
    public function recordPending(
        StripeWebhookReconciliationDTO $reconciliation,
        string $errorClass,
    ): void {
        DB::transaction(function () use ($reconciliation, $errorClass): void {
            $now = now();
            $inserted = StripeWebhookReconciliation::query()->insertOrIgnore([
                ...$this->identityAttributes($reconciliation),
                'reason_code' => $reconciliation->reason->value,
                'status' => StripeWebhookReconciliationStatus::PENDING->value,
                'attempts' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                'last_error_class' => substr($errorClass, 0, 255),
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 1) {
                return;
            }

            $existing = $this->identityQuery($reconciliation)->lockForUpdate()->firstOrFail();
            $attributes = [
                ...$this->linkAttributes($reconciliation),
                'attempts' => DB::raw('attempts + 1'),
                'last_seen_at' => $now,
                'last_error_class' => substr($errorClass, 0, 255),
                'updated_at' => $now,
            ];

            if ($existing->status === StripeWebhookReconciliationStatus::PENDING->value) {
                $attributes['reason_code'] = $reconciliation->reason->value;
            }

            StripeWebhookReconciliation::query()->whereKey($existing->getKey())->update($attributes);
        });
    }

    public function resolveExisting(StripeWebhookReconciliationDTO $reconciliation): void
    {
        $now = now();
        $this->identityQuery($reconciliation)
            ->whereIn('status', [
                StripeWebhookReconciliationStatus::PENDING->value,
                StripeWebhookReconciliationStatus::MANUAL_REVIEW->value,
            ])
            ->update([
                ...$this->linkAttributes($reconciliation),
                'status' => StripeWebhookReconciliationStatus::RESOLVED->value,
                'resolved_at' => $now,
                'manual_review_at' => null,
                'last_seen_at' => $now,
                'last_error_class' => null,
                'updated_at' => $now,
            ]);
    }

    public function recordAudit(
        StripeWebhookReconciliationDTO $reconciliation,
        StripeWebhookReconciliationStatus $status,
    ): void {
        if ($status === StripeWebhookReconciliationStatus::PENDING) {
            throw new InvalidArgumentException('Audit reconciliation status cannot be pending.');
        }

        DB::transaction(function () use ($reconciliation, $status): void {
            $now = now();
            $timestamps = $this->terminalTimestamps($status, $now);
            $inserted = StripeWebhookReconciliation::query()->insertOrIgnore([
                ...$this->identityAttributes($reconciliation),
                'reason_code' => $reconciliation->reason->value,
                'status' => $status->value,
                'attempts' => 1,
                'first_seen_at' => $now,
                'last_seen_at' => $now,
                ...$timestamps,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 1) {
                return;
            }

            $existing = $this->identityQuery($reconciliation)->lockForUpdate()->firstOrFail();
            StripeWebhookReconciliation::query()->whereKey($existing->getKey())->update([
                ...$this->linkAttributes($reconciliation),
                'reason_code' => $reconciliation->reason->value,
                'status' => $status->value,
                'attempts' => DB::raw('attempts + 1'),
                'last_seen_at' => $now,
                'last_error_class' => null,
                ...$timestamps,
                'updated_at' => $now,
            ]);
        });
    }

    public function agePendingBefore(DateTimeInterface $cutoff, int $limit): int
    {
        if ($limit < 1 || $limit > 1000) {
            throw new InvalidArgumentException('Reconciliation aging batch size must be between 1 and 1000.');
        }

        return DB::transaction(function () use ($cutoff, $limit): int {
            $query = StripeWebhookReconciliation::query()
                ->where('status', StripeWebhookReconciliationStatus::PENDING->value)
                ->where('first_seen_at', '<=', $cutoff)
                ->orderBy('id')
                ->limit($limit);

            if (DB::connection()->getDriverName() === 'pgsql') {
                $query->lock('FOR UPDATE SKIP LOCKED');
            } else {
                $query->lockForUpdate();
            }

            $ids = $query->pluck('id');
            if ($ids->isEmpty()) {
                return 0;
            }

            $now = now();

            return StripeWebhookReconciliation::query()
                ->whereKey($ids)
                ->where('status', StripeWebhookReconciliationStatus::PENDING->value)
                ->update([
                    'status' => StripeWebhookReconciliationStatus::MANUAL_REVIEW->value,
                    'manual_review_at' => $now,
                    'updated_at' => $now,
                ]);
        });
    }

    private function identityQuery(StripeWebhookReconciliationDTO $reconciliation): Builder
    {
        return StripeWebhookReconciliation::query()
            ->where('event_id', $reconciliation->eventId)
            ->where('provider_object_type', $reconciliation->providerObjectType)
            ->where('provider_object_id', $reconciliation->providerObjectId);
    }

    private function identityAttributes(StripeWebhookReconciliationDTO $reconciliation): array
    {
        return [
            'event_id' => $reconciliation->eventId,
            'event_type' => $reconciliation->eventType,
            'stripe_account_id' => $reconciliation->stripeAccountId,
            'provider_object_type' => $reconciliation->providerObjectType,
            'provider_object_id' => $reconciliation->providerObjectId,
            ...$this->linkAttributes($reconciliation),
        ];
    }

    private function linkAttributes(StripeWebhookReconciliationDTO $reconciliation): array
    {
        return [
            'payment_intent_id' => $reconciliation->paymentIntentId,
            'charge_id' => $reconciliation->chargeId,
            'refund_id' => $reconciliation->refundId,
            'order_id' => $reconciliation->orderId,
            'stripe_payment_id' => $reconciliation->stripePaymentId,
        ];
    }

    private function terminalTimestamps(StripeWebhookReconciliationStatus $status, mixed $now): array
    {
        return [
            'resolved_at' => $status === StripeWebhookReconciliationStatus::RESOLVED ? $now : null,
            'manual_review_at' => $status === StripeWebhookReconciliationStatus::MANUAL_REVIEW ? $now : null,
        ];
    }
}
