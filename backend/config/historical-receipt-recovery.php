<?php

// Runtime secret maps: version => standard-base64 32-byte key. Malformed maps fail closed.
// Separate encryption/integrity custody; never fall back to APP_KEY.
$keyMap = static function (mixed $encoded): array {
    if (! is_string($encoded) || $encoded === '') {
        return [];
    }
    try {
        $map = json_decode($encoded, false, 8, JSON_THROW_ON_ERROR);
        if (! $map instanceof \stdClass) {
            return [];
        }
        $keys = [];
        foreach (get_object_vars($map) as $version => $value) {
            if (preg_match('/\A[A-Za-z][A-Za-z0-9:._-]{0,63}\z/', (string) $version) !== 1 || ! is_string($value)) {
                return [];
            }
            $key = base64_decode($value, true);
            if ($key === false || strlen($key) !== 32 || base64_encode($key) !== $value) {
                return [];
            }
            $keys[$version] = $key;
        }

        return $keys;
    } catch (\JsonException) {
        return [];
    }
};

return [
    'enabled' => env('KAMP_HISTORICAL_RECEIPT_ENABLED', false),
    'import_enabled' => env('KAMP_HISTORICAL_RECEIPT_IMPORT_ENABLED', false),
    'approved_manifest_digest' => env('KAMP_HISTORICAL_RECEIPT_MANIFEST_DIGEST'),
    'encryption_key_version' => env('KAMP_HISTORICAL_RECEIPT_ENCRYPTION_KEY_VERSION'),
    'integrity_key_version' => env('KAMP_HISTORICAL_RECEIPT_INTEGRITY_KEY_VERSION'),
    'encryption_keys' => $keyMap(env('KAMP_HISTORICAL_RECEIPT_ENCRYPTION_KEYS')),
    'integrity_keys' => $keyMap(env('KAMP_HISTORICAL_RECEIPT_INTEGRITY_KEYS')),
];
