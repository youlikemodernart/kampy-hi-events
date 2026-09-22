<?php

declare(strict_types=1);

namespace Tests\Feature\Registration;

use HiEvents\Services\Domain\Registration\GvsuRegistrationBridgeService;
use Mockery;
use Tests\TestCase;

class GvsuRegistrationCurrentStateRouteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('a', 32));
    }

    private array $candidate = [
        'operation' => 'gvsu-registration-current-state-v1',
        'event_id' => '7',
        'order_id' => '91',
        'attendee_id' => '92',
        'public_ticket_id' => 'ticket_opaque',
        'respondent_id' => 'grr_opaque',
        'assignment_id' => 'gra_opaque',
        'attendee_display_name' => 'Registered Attendee',
        'respondent_identity_digest_sha256' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
        'designated_delivery_email' => 'person@example.com',
    ];

    public function test_disabled_or_unauthenticated_current_state_is_undiscoverable(): void
    {
        config()->set('services.gvsu_registration_bridge.mode', 'disabled');
        $this->postJson('/internal/gvsu-registration/current-state', $this->candidate)
            ->assertNotFound();
        $this->postJson('/internal/gvsu-registration/current-state', [])
            ->assertNotFound();

        config()->set('services.gvsu_registration_bridge.mode', 'live');
        $this->withHeader('Authorization', 'Bearer '.str_repeat('b', 43))
            ->postJson('/internal/gvsu-registration/current-state', [])
            ->assertNotFound();
        config()->set('services.gvsu_registration_bridge.incoming_current_digest', 'invalid');
        $this->withHeader('Authorization', 'Bearer '.str_repeat('b', 43))
            ->postJson('/internal/gvsu-registration/current-state', [])
            ->assertNotFound();
        config()->set('services.gvsu_registration_bridge.incoming_current_digest', hash('sha256', str_repeat('a', 43)));
        $this->postJson('/internal/gvsu-registration/current-state', $this->candidate)
            ->assertNotFound();
        $this->postJson('/internal/gvsu-registration/current-state', [])
            ->assertNotFound();
    }

    public function test_authenticated_malformed_current_state_request_is_rejected_after_the_credential_gate(): void
    {
        $bearer = str_repeat('a', 43);
        config()->set('services.gvsu_registration_bridge.mode', 'live');
        config()->set('services.gvsu_registration_bridge.incoming_current_digest', hash('sha256', $bearer));
        $bridge = Mockery::mock(GvsuRegistrationBridgeService::class);
        $bridge->shouldNotReceive('currentState');
        $this->app->instance(GvsuRegistrationBridgeService::class, $bridge);

        $this->withHeader('Authorization', 'Bearer '.$bearer)
            ->postJson('/internal/gvsu-registration/current-state', [])
            ->assertUnprocessable();
    }

    public function test_current_state_validates_request_only_attendee_context_and_delivery_email_without_echoing_them(): void
    {
        $bearer = str_repeat('a', 43);
        config()->set('services.gvsu_registration_bridge.mode', 'live');
        config()->set('services.gvsu_registration_bridge.incoming_current_digest', hash('sha256', $bearer));
        config()->set('services.gvsu_registration_bridge.incoming_prior_digest', null);
        $bridge = Mockery::mock(GvsuRegistrationBridgeService::class);
        $bridge->shouldReceive('currentState')->once()->with(Mockery::on(function (array $candidate): bool {
            return $candidate['designated_delivery_email'] === 'person@example.com'
                && $candidate['attendee_display_name'] === 'Registered Attendee'
                && $candidate['respondent_identity_digest_sha256'] === str_repeat('a', 64);
        }))->andReturn([
            'status' => 'current',
            'observed_at' => '2026-09-22T16:00:00Z',
            'respondent_identity_digest_sha256' => str_repeat('a', 64),
            'snapshot_digest_sha256' => str_repeat('b', 64),
        ]);
        $this->app->instance(GvsuRegistrationBridgeService::class, $bridge);

        $response = $this->withHeader('Authorization', 'Bearer '.$bearer)
            ->postJson('/internal/gvsu-registration/current-state', $this->candidate)
            ->assertOk()
            ->assertExactJson([
                'status' => 'current',
                'observed_at' => '2026-09-22T16:00:00Z',
                'respondent_identity_digest_sha256' => str_repeat('a', 64),
                'snapshot_digest_sha256' => str_repeat('b', 64),
            ]);

        self::assertStringNotContainsString('person@example.com', $response->getContent());
        self::assertStringNotContainsString('Registered Attendee', $response->getContent());
    }
}
