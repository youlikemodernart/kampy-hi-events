<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\Exceptions\StripeWebhookEventClaimBusyException;
use HiEvents\Models\StripeWebhookEvent;
use HiEvents\Repository\Interfaces\StripeWebhookEventRepositoryInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

class StripeWebhookEventRepository implements StripeWebhookEventRepositoryInterface
{
    private const STATUS_PROCESSING = 'PROCESSING';

    private const STATUS_HANDLED = 'HANDLED';

    private const STATUS_FAILED = 'FAILED';

    private const CLAIM_TIMEOUT_MINUTES = 15;

    public function claim(string $eventId, string $eventType, ?string $stripeAccountId): ?string
    {
        $now = now();
        $claimToken = (string) Str::uuid();

        $inserted = StripeWebhookEvent::query()->insertOrIgnore([
            'event_id' => $eventId,
            'event_type' => $eventType,
            'stripe_account_id' => $stripeAccountId,
            'status' => self::STATUS_PROCESSING,
            'claim_token' => $claimToken,
            'attempts' => 1,
            'claimed_at' => $now,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        if ($inserted === 1) {
            return $claimToken;
        }

        $staleBefore = $now->copy()->subMinutes(self::CLAIM_TIMEOUT_MINUTES);
        $reclaimed = StripeWebhookEvent::query()
            ->where('event_id', $eventId)
            ->where(static function (Builder $query) use ($staleBefore): void {
                $query->where('status', self::STATUS_FAILED)
                    ->orWhere(static function (Builder $query) use ($staleBefore): void {
                        $query->where('status', self::STATUS_PROCESSING)
                            ->where('claimed_at', '<=', $staleBefore);
                    });
            })
            ->update([
                'event_type' => $eventType,
                'stripe_account_id' => $stripeAccountId,
                'status' => self::STATUS_PROCESSING,
                'claim_token' => $claimToken,
                'attempts' => DB::raw('attempts + 1'),
                'claimed_at' => $now,
                'handled_at' => null,
                'last_error_class' => null,
                'updated_at' => $now,
            ]);

        if ($reclaimed === 1) {
            return $claimToken;
        }

        $status = StripeWebhookEvent::query()
            ->where('event_id', $eventId)
            ->value('status');

        if ($status === self::STATUS_HANDLED) {
            return null;
        }

        throw new StripeWebhookEventClaimBusyException;
    }

    public function markHandled(string $eventId, string $claimToken): void
    {
        $now = now();
        $updated = StripeWebhookEvent::query()
            ->where('event_id', $eventId)
            ->where('status', self::STATUS_PROCESSING)
            ->where('claim_token', $claimToken)
            ->update([
                'status' => self::STATUS_HANDLED,
                'handled_at' => $now,
                'last_error_class' => null,
                'updated_at' => $now,
            ]);

        if ($updated !== 1) {
            throw new RuntimeException('Unable to mark Stripe webhook event as handled by the active claim.');
        }
    }

    public function markFailed(string $eventId, string $claimToken, string $errorClass): void
    {
        StripeWebhookEvent::query()
            ->where('event_id', $eventId)
            ->where('status', self::STATUS_PROCESSING)
            ->where('claim_token', $claimToken)
            ->update([
                'status' => self::STATUS_FAILED,
                'claimed_at' => null,
                'last_error_class' => substr($errorClass, 0, 255),
                'updated_at' => now(),
            ]);
    }
}
