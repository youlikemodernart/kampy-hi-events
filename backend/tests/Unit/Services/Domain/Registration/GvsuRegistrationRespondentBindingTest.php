<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Registration;

use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Services\Domain\Registration\GvsuRegistrationBridgePortalClient;
use HiEvents\Services\Domain\Registration\GvsuRegistrationBridgeService;
use Mockery;
use ReflectionMethod;
use Tests\TestCase;

class GvsuRegistrationRespondentBindingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('services.gvsu_registration_bridge.email_hmac_current_key', str_repeat('a', 43));
        config()->set('services.gvsu_registration_bridge.email_hmac_prior_key', null);
    }

    public function test_adult_and_guardian_routes_are_explicit_and_guardian_reference_is_never_inferred(): void
    {
        $service = $this->service();
        $route = new ReflectionMethod($service, 'normalizedRoute');
        $relationship = new ReflectionMethod($service, 'normalizedGuardianRelationshipReference');

        self::assertSame('adult', $route->invoke($service, 'adult'));
        self::assertSame('guardian', $route->invoke($service, 'guardian'));
        self::assertNull($relationship->invoke($service, null, 'guardian'));
        self::assertSame('guardian:parent', $relationship->invoke($service, 'guardian:parent', 'guardian'));

        $this->expectException(ResourceConflictException::class);
        $relationship->invoke($service, 'guardian:parent', 'adult');
    }

    public function test_respondent_identity_digest_binds_name_route_relationship_and_destination_against_tamper(): void
    {
        $service = $this->service();
        $digest = new ReflectionMethod($service, 'identityDigest');
        $matches = new ReflectionMethod($service, 'matchesIdentityDigest');
        $bound = $digest->invoke($service, 'Registered Attendee', 'Confirmed Adult', 'adult', null, 'adult@example.test');

        self::assertTrue($matches->invoke($service, $bound, 'Registered Attendee', 'Confirmed Adult', 'adult', null, 'adult@example.test'));
        self::assertFalse($matches->invoke($service, $bound, 'Different Attendee', 'Confirmed Adult', 'adult', null, 'adult@example.test'));
        self::assertFalse($matches->invoke($service, $bound, 'Registered Attendee', 'Different Person', 'adult', null, 'adult@example.test'));
        self::assertFalse($matches->invoke($service, $bound, 'Registered Attendee', 'Confirmed Adult', 'guardian', 'guardian:parent', 'adult@example.test'));
        self::assertFalse($matches->invoke($service, $bound, 'Registered Attendee', 'Confirmed Adult', 'adult', null, 'other@example.test'));
    }

    public function test_missing_assignment_stays_pending_and_correction_carries_a_link_replacement_trigger(): void
    {
        $bridge = file_get_contents(app_path('Services/Domain/Registration/GvsuRegistrationBridgeService.php'));
        $relay = file_get_contents(app_path('Services/Domain/Order/OrderEffectRelayService.php'));
        self::assertIsString($bridge);
        self::assertIsString($relay);
        self::assertStringContainsString('if ($assignments->count() !== $attendees->count()) {', $bridge);
        self::assertStringContainsString('return false;', $bridge);
        self::assertStringContainsString("'replaced_assignment_id' => \$existing->assignment_id", $bridge);
        self::assertStringContainsString("'link_replacement_requested_at' => \$linkReplacementRequired ? now() : null", $bridge);
        self::assertStringContainsString("'attendee_display_name' => \$assignment->attendee_display_name", $bridge);
        self::assertStringContainsString('respondent_identity_digest_sha256', $bridge);
        self::assertStringContainsString("['bound', 'attempted', 'unknown', 'delivered']", $bridge);
        self::assertStringNotContainsString("\$assignment->status !== 'delivered'", $bridge);
        self::assertStringContainsString('if (($this->registrationBridgeService ?? app(GvsuRegistrationBridgeService::class))->provisionCompletedOrder($effect->orderId))', $relay);
    }

    public function test_exact_canary_reconciliation_remains_order_scoped_and_public_checkin_uses_clearance(): void
    {
        $command = file_get_contents(app_path('Console/Commands/BindGvsuRegistrationRespondent.php'));
        $reconcile = file_get_contents(app_path('Console/Commands/ReconcileGvsuRegistrationOrder.php'));
        $publicCheckIn = file_get_contents(app_path('Services/Domain/CheckInList/CreateAttendeeCheckInService.php'));
        self::assertIsString($command);
        self::assertIsString($reconcile);
        self::assertIsString($publicCheckIn);
        self::assertStringContainsString('gvsu-registration:bind-respondent', $command);
        self::assertStringContainsString('{orderId', $command);
        self::assertStringContainsString('{attendeeId', $command);
        self::assertStringContainsString('--attendee-display-name=', $command);
        self::assertStringContainsString('gvsu-registration:reconcile-order {orderId', $reconcile);
        self::assertStringContainsString('assertAttendeeCleared(', $publicCheckIn);
    }

    private function service(): GvsuRegistrationBridgeService
    {
        return new GvsuRegistrationBridgeService(Mockery::mock(GvsuRegistrationBridgePortalClient::class));
    }
}
