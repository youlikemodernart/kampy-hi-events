<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StripeWebhookReconciliation extends BaseModel
{
    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function stripePayment(): BelongsTo
    {
        return $this->belongsTo(StripePayment::class);
    }

    protected function getCastMap(): array
    {
        return [
            'attempts' => 'integer',
            'first_seen_at' => 'datetime',
            'last_seen_at' => 'datetime',
            'resolved_at' => 'datetime',
            'manual_review_at' => 'datetime',
        ];
    }

    protected function getFillableFields(): array
    {
        return [
            'event_id',
            'event_type',
            'stripe_account_id',
            'provider_object_type',
            'provider_object_id',
            'payment_intent_id',
            'charge_id',
            'refund_id',
            'order_id',
            'stripe_payment_id',
            'reason_code',
            'status',
            'attempts',
            'first_seen_at',
            'last_seen_at',
            'resolved_at',
            'manual_review_at',
            'last_error_class',
        ];
    }
}
