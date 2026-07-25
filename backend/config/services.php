<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        'scheme' => 'https',
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'stripe' => [
        'secret_key' => env('STRIPE_SECRET_KEY'),
        'public_key' => env('STRIPE_PUBLIC_KEY'),
        'webhook_secret' => env('STRIPE_WEBHOOK_SECRET'),

        // Canadian platform (Optional)
        'ca_secret_key' => env('STRIPE_CA_SECRET_KEY', env('STRIPE_SECRET_KEY')),
        'ca_public_key' => env('STRIPE_CA_PUBLIC_KEY', env('STRIPE_PUBLIC_KEY')),
        'ca_webhook_secret' => env('STRIPE_CA_WEBHOOK_SECRET', env('STRIPE_WEBHOOK_SECRET')),

        // Irish platform (Optional)
        'ie_secret_key' => env('STRIPE_IE_SECRET_KEY', env('STRIPE_SECRET_KEY')),
        'ie_public_key' => env('STRIPE_IE_PUBLIC_KEY', env('STRIPE_PUBLIC_KEY')),
        'ie_webhook_secret' => env('STRIPE_IE_WEBHOOK_SECRET', env('STRIPE_WEBHOOK_SECRET')),

        // Primary platform for new organizers
        'primary_platform' => env('STRIPE_PRIMARY_PLATFORM'),

        // Required in SaaS mode before connected-account refunds: retain or return
        'connect_refund_application_fee_policy' => env('STRIPE_CONNECT_REFUND_APPLICATION_FEE_POLICY'),

        // Canonical provisioning contract for connected-account webhook endpoints
        'connect_webhook_path' => \HiEvents\Services\Infrastructure\Stripe\StripeConnectWebhookContract::PATH,
        'connect_webhook_events' => \HiEvents\Services\Infrastructure\Stripe\StripeConnectWebhookContract::EVENT_TYPES,

        // Local-only missing-payment reconciliation aging policy
        'webhook_reconciliation_grace_hours' => env('STRIPE_WEBHOOK_RECONCILIATION_GRACE_HOURS', 72),
        'webhook_reconciliation_batch_size' => env('STRIPE_WEBHOOK_RECONCILIATION_BATCH_SIZE', 100),
    ],
    'order_effect_outbox' => [
        'batch_size' => env('ORDER_EFFECT_OUTBOX_BATCH_SIZE', 25),
        'max_attempts' => env('ORDER_EFFECT_OUTBOX_MAX_ATTEMPTS', 10),
    ],
    'kamp_stripe_metadata' => [
        'enabled' => env('KAMP_STRIPE_METADATA_ENABLED', false),
        'source_namespace' => env('KAMP_STRIPE_SOURCE_NAMESPACE'),
        'event_map' => env('KAMP_STRIPE_EVENT_MAP', '{}'),
    ],
    'open_exchange_rates' => [
        'app_id' => env('OPEN_EXCHANGE_RATES_APP_ID'),
    ],
];
