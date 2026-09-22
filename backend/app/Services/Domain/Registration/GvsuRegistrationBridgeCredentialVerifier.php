<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Registration;

final class GvsuRegistrationBridgeCredentialVerifier
{
    public function accepts(?string $presentedBearer): bool
    {
        if (! is_string($presentedBearer) || preg_match('/\A[A-Za-z0-9_-]{43}\z/', $presentedBearer) !== 1) {
            return false;
        }

        $presentedDigest = hash('sha256', $presentedBearer);
        $current = GvsuRegistrationBridgeConfig::incomingCurrentDigest();
        $prior = GvsuRegistrationBridgeConfig::incomingPriorDigest();

        $currentMatch = hash_equals($current, $presentedDigest);
        $priorMatch = $prior !== null && hash_equals($prior, $presentedDigest);

        return $currentMatch || $priorMatch;
    }
}
