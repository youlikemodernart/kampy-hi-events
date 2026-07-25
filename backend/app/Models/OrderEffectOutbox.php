<?php

declare(strict_types=1);

namespace HiEvents\Models;

class OrderEffectOutbox extends BaseModel
{
    protected $table = 'order_effect_outbox';

    protected function getCastMap(): array
    {
        return [
            'order_id' => 'integer',
            'attempts' => 'integer',
            'available_at' => 'datetime',
            'claimed_at' => 'datetime',
            'delivered_at' => 'datetime',
            'manual_review_at' => 'datetime',
        ];
    }

    protected function getFillableFields(): array
    {
        return [
            'delivery_id',
            'business_key',
            'order_id',
            'effect_type',
            'transition_key',
            'domain_event_type',
            'email_kind',
            'status',
            'attempts',
            'available_at',
            'claimed_at',
            'claim_token',
            'delivered_at',
            'manual_review_at',
            'last_error_class',
        ];
    }
}
