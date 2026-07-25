<?php

declare(strict_types=1);

namespace HiEvents\Models;

class StripeWebhookEvent extends BaseModel
{
    protected function getCastMap(): array
    {
        return [
            'attempts' => 'integer',
            'claimed_at' => 'datetime',
            'handled_at' => 'datetime',
        ];
    }

    protected function getFillableFields(): array
    {
        return [
            'event_id',
            'event_type',
            'stripe_account_id',
            'status',
            'claim_token',
            'attempts',
            'claimed_at',
            'handled_at',
            'last_error_class',
        ];
    }
}
