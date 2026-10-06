<?php

return [
    'enabled' => env('KAMP_HISTORICAL_RECEIPT_ENABLED', false),
    'import_enabled' => env('KAMP_HISTORICAL_RECEIPT_IMPORT_ENABLED', false),
    'approved_manifest_digest' => env('KAMP_HISTORICAL_RECEIPT_MANIFEST_DIGEST'),
    'encryption_key_version' => env('KAMP_HISTORICAL_RECEIPT_ENCRYPTION_KEY_VERSION'),
    'integrity_key_version' => env('KAMP_HISTORICAL_RECEIPT_INTEGRITY_KEY_VERSION'),
    // Explicit versioned keys, no fallback to mutable application keys.
    'encryption_keys' => [],
    'integrity_keys' => [],
];
