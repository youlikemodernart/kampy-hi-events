<?php

declare(strict_types=1);

namespace HiEvents\Models;

class StripeRefundRequest extends BaseModel
{
    protected function getCastMap(): array
    {
        return [
            'amount_minor' => 'integer',
            'notify_buyer' => 'boolean',
            'cancel_order' => 'boolean',
            'refund_application_fee' => 'boolean',
            'attempts' => 'integer',
            'last_attempted_at' => 'datetime',
            'provider_accepted_at' => 'datetime',
            'cancel_applied_at' => 'datetime',
            'notification_claimed_at' => 'datetime',
            'notification_sent_at' => 'datetime',
        ];
    }

    protected function getFillableFields(): array
    {
        return [
            'request_id',
            'order_id',
            'stripe_payment_id',
            'payment_intent_id',
            'stripe_account_id',
            'amount_minor',
            'currency',
            'notify_buyer',
            'cancel_order',
            'refund_application_fee',
            'status',
            'attempts',
            'provider_refund_id',
            'provider_status',
            'last_error_class',
            'last_attempted_at',
            'provider_accepted_at',
            'cancel_applied_at',
            'notification_claimed_at',
            'notification_sent_at',
        ];
    }
}
