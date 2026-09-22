<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Registration;

use HiEvents\Services\Domain\Registration\GvsuRegistrationBridgeCredentialVerifier;
use Tests\TestCase;

class GvsuRegistrationBridgeCredentialVerifierTest extends TestCase
{
    public function test_current_and_prior_credentials_are_accepted_in_constant_digest_format(): void
    {
        $current = str_repeat('a', 43);
        $prior = str_repeat('b', 43);
        config()->set('services.gvsu_registration_bridge.incoming_current_digest', hash('sha256', $current));
        config()->set('services.gvsu_registration_bridge.incoming_prior_digest', hash('sha256', $prior));

        $verifier = new GvsuRegistrationBridgeCredentialVerifier;

        self::assertTrue($verifier->accepts($current));
        self::assertTrue($verifier->accepts($prior));
        self::assertFalse($verifier->accepts(str_repeat('c', 43)));
        self::assertFalse($verifier->accepts('short'));
    }
}
