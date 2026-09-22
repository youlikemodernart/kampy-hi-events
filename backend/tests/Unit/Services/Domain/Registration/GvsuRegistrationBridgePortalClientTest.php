<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Registration;

use HiEvents\Exceptions\GvsuRegistrationBridgeUnknownException;
use HiEvents\Services\Domain\Registration\GvsuRegistrationBridgePortalClient;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class GvsuRegistrationBridgePortalClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.gvsu_registration_bridge.portal_host', 'portal.example.test');
        config()->set('services.gvsu_registration_bridge.outgoing_bearer', str_repeat('a', 43));
    }

    public function test_provision_uses_fixed_https_path_bearer_and_no_redirect_response(): void
    {
        Http::fake(['https://portal.example.test/api/internal/gvsu-registration/provision' => Http::response(['classification' => 'accepted'])]);

        app(GvsuRegistrationBridgePortalClient::class)->provision(['provision_batch_id' => 'grb_test']);

        Http::assertSent(static function ($request): bool {
            return $request->url() === 'https://portal.example.test/api/internal/gvsu-registration/provision'
                && $request->hasHeader('Authorization', 'Bearer '.str_repeat('a', 43));
        });
    }

    public function test_timeout_or_non_acceptance_is_unknown_and_never_redirected_or_retried_here(): void
    {
        Http::fake(static fn () => throw new ConnectionException('network'));
        $this->expectException(GvsuRegistrationBridgeUnknownException::class);

        app(GvsuRegistrationBridgePortalClient::class)->provision(['provision_batch_id' => 'grb_test']);
    }
}
