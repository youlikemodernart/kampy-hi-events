<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\Stripe;

final class StripeProviderErrorSanitizer
{
    public static function sanitize(?object $error): ?array
    {
        if ($error === null) {
            return null;
        }

        $safe = [];

        foreach (['type', 'code', 'decline_code'] as $field) {
            $value = $error->{$field} ?? null;

            if (is_string($value) && $value !== '') {
                $safe[$field] = $value;
            }
        }

        return $safe === [] ? null : $safe;
    }
}
