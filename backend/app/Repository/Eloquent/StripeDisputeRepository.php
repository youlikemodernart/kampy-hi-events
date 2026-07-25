<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\Models\StripeDispute;
use HiEvents\Repository\Interfaces\StripeDisputeRepositoryInterface;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\StripeDisputeDTO;
use Illuminate\Support\Facades\DB;

class StripeDisputeRepository implements StripeDisputeRepositoryInterface
{
    private const TERMINAL_STATUSES = ['won', 'lost', 'warning_closed'];

    public function upsert(StripeDisputeDTO $dispute): void
    {
        DB::transaction(function () use ($dispute): void {
            $now = now();
            $attributes = $this->attributes($dispute);
            $inserted = StripeDispute::query()->insertOrIgnore([
                'dispute_id' => $dispute->disputeId,
                ...$attributes,
                'created_at' => $now,
                'updated_at' => $now,
            ]);

            if ($inserted === 1) {
                return;
            }

            $existing = StripeDispute::query()
                ->where('dispute_id', $dispute->disputeId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($existing->last_event_id === $dispute->lastEventId) {
                return;
            }

            if ($existing->last_event_created_at->greaterThan($dispute->lastEventCreatedAt)) {
                return;
            }

            if (in_array($existing->status, self::TERMINAL_STATUSES, true)
                && ! in_array($dispute->status, self::TERMINAL_STATUSES, true)) {
                return;
            }

            if ($existing->last_event_created_at->equalTo($dispute->lastEventCreatedAt)
                && $this->eventPrecedence($existing->last_event_type) >= $this->eventPrecedence($dispute->lastEventType)) {
                return;
            }

            StripeDispute::query()
                ->whereKey($existing->getKey())
                ->update([
                    ...$attributes,
                    'updated_at' => $now,
                ]);
        });
    }

    public function linkPendingToPayment(
        int $orderId,
        int $stripePaymentId,
        string $paymentIntentId,
        ?string $chargeId,
        ?string $stripeAccountId,
    ): int {
        return StripeDispute::query()
            ->whereNull('order_id')
            ->whereNull('stripe_payment_id')
            ->where('stripe_account_id', $stripeAccountId)
            ->where(static function ($query) use ($paymentIntentId, $chargeId): void {
                $query->where('payment_intent_id', $paymentIntentId);

                if ($chargeId !== null) {
                    $query->orWhere('charge_id', $chargeId);
                }
            })
            ->update([
                'order_id' => $orderId,
                'stripe_payment_id' => $stripePaymentId,
                'updated_at' => now(),
            ]);
    }

    private function eventPrecedence(string $eventType): int
    {
        return match ($eventType) {
            'charge.dispute.closed' => 3,
            'charge.dispute.updated' => 2,
            'charge.dispute.created' => 1,
            default => 0,
        };
    }

    private function attributes(StripeDisputeDTO $dispute): array
    {
        return [
            'order_id' => $dispute->orderId,
            'stripe_payment_id' => $dispute->stripePaymentId,
            'payment_intent_id' => $dispute->paymentIntentId,
            'charge_id' => $dispute->chargeId,
            'stripe_account_id' => $dispute->stripeAccountId,
            'amount_minor' => $dispute->amountMinor,
            'currency' => $dispute->currency,
            'status' => $dispute->status,
            'reason' => $dispute->reason,
            'balance_transaction_ids' => json_encode($dispute->balanceTransactionIds, JSON_THROW_ON_ERROR),
            'evidence_due_at' => $dispute->evidenceDueAt,
            'closed_at' => $dispute->closedAt,
            'provider_created_at' => $dispute->providerCreatedAt,
            'last_event_id' => $dispute->lastEventId,
            'last_event_type' => $dispute->lastEventType,
            'last_event_created_at' => $dispute->lastEventCreatedAt,
        ];
    }
}
