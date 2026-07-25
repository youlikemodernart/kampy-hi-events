<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StripeDispute extends BaseModel
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
            'amount_minor' => 'integer',
            'balance_transaction_ids' => 'array',
            'evidence_due_at' => 'datetime',
            'closed_at' => 'datetime',
            'provider_created_at' => 'datetime',
            'last_event_created_at' => 'datetime',
        ];
    }

    protected function getFillableFields(): array
    {
        return [
            'dispute_id',
            'order_id',
            'stripe_payment_id',
            'payment_intent_id',
            'charge_id',
            'stripe_account_id',
            'amount_minor',
            'currency',
            'status',
            'reason',
            'balance_transaction_ids',
            'evidence_due_at',
            'closed_at',
            'provider_created_at',
            'last_event_id',
            'last_event_type',
            'last_event_created_at',
        ];
    }
}
