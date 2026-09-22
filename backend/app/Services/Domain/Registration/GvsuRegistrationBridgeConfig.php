<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Registration;

use HiEvents\Exceptions\ResourceConflictException;

final class GvsuRegistrationBridgeConfig
{
    public const EVENT_ID = 7;

    public const PROVISION_PATH = '/api/internal/gvsu-registration/provision';

    public const CLEARANCE_PATH = '/api/internal/gvsu-registration/clearance';

    public static function enabled(): bool
    {
        return self::mode() !== 'disabled';
    }

    /** Disabled is the safe default; canary requires a configured exact order allowlist. */
    public static function mode(): string
    {
        $mode = config('services.gvsu_registration_bridge.mode');
        if ($mode === null && config('services.gvsu_registration_bridge.enabled', false) === true) {
            return 'live';
        }

        return in_array($mode, ['disabled', 'dark', 'canary', 'live'], true) ? $mode : 'disabled';
    }

    /** Canary is bound to the exact selected order, never to one attendee in an order. */
    public static function allowsCohort(int $eventId, int $orderId): bool
    {
        if ($eventId !== self::EVENT_ID || ! self::enabled()) {
            return false;
        }
        if (self::mode() !== 'canary') {
            return true;
        }

        $orderAllowlist = config('services.gvsu_registration_bridge.canary_order_ids', []);

        return is_array($orderAllowlist) && in_array($orderId, $orderAllowlist, true);
    }

    /** An event-seven completion can create bridge work only for the exact selected order. */
    public static function mayEnqueueOrder(int $eventId, int $orderId): bool
    {
        return self::allowsCohort($eventId, $orderId);
    }

    public static function portalUrl(string $path): string
    {
        $host = config('services.gvsu_registration_bridge.portal_host');
        if (! is_string($host) || preg_match('/\A[a-z0-9](?:[a-z0-9.-]{0,251}[a-z0-9])?\z/i', $host) !== 1) {
            throw new ResourceConflictException(__('GVSU registration bridge peer is not configured.'));
        }

        return 'https://'.strtolower($host).$path;
    }

    public static function outgoingBearer(): string
    {
        return self::requiredSecret('outgoing_bearer');
    }

    public static function incomingCurrentDigest(): string
    {
        return self::requiredDigest('incoming_current_digest');
    }

    public static function incomingPriorDigest(): ?string
    {
        return self::optionalDigest('incoming_prior_digest');
    }

    public static function emailHmacCurrentKey(): string
    {
        return self::requiredSecret('email_hmac_current_key');
    }

    public static function emailHmacPriorKey(): ?string
    {
        $value = config('services.gvsu_registration_bridge.email_hmac_prior_key');

        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function requiredSecret(string $key): string
    {
        $value = config("services.gvsu_registration_bridge.{$key}");
        if (! is_string($value) || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $value) !== 1) {
            throw new ResourceConflictException(__('GVSU registration bridge credential is not configured.'));
        }

        return $value;
    }

    private static function requiredDigest(string $key): string
    {
        $value = config("services.gvsu_registration_bridge.{$key}");
        if (! is_string($value) || preg_match('/\A[0-9a-f]{64}\z/', $value) !== 1) {
            throw new ResourceConflictException(__('GVSU registration bridge credential is not configured.'));
        }

        return $value;
    }

    private static function optionalDigest(string $key): ?string
    {
        $value = config("services.gvsu_registration_bridge.{$key}");
        if ($value === null || $value === '') {
            return null;
        }
        if (! is_string($value) || preg_match('/\A[0-9a-f]{64}\z/', $value) !== 1) {
            throw new ResourceConflictException(__('GVSU registration bridge credential is invalid.'));
        }

        return $value;
    }
}
