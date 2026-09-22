<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Registration;

use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Services\Domain\Registration\GvsuRegistrationBridgeConfig;
use Tests\TestCase;

class GvsuRegistrationBridgeSourceContractTest extends TestCase
{
    public function test_fixed_portal_peer_rejects_paths_ports_and_non_tls_hosts(): void
    {
        config()->set('services.gvsu_registration_bridge.portal_host', 'portal.example.test');
        self::assertSame(
            'https://portal.example.test/api/internal/gvsu-registration/provision',
            GvsuRegistrationBridgeConfig::portalUrl(GvsuRegistrationBridgeConfig::PROVISION_PATH),
        );

        foreach (['http://portal.example.test', 'portal.example.test:443', 'portal.example.test/path'] as $host) {
            config()->set('services.gvsu_registration_bridge.portal_host', $host);
            try {
                GvsuRegistrationBridgeConfig::portalUrl(GvsuRegistrationBridgeConfig::PROVISION_PATH);
                self::fail('Expected fixed peer rejection.');
            } catch (ResourceConflictException) {
                self::assertTrue(true);
            }
        }
    }

    public function test_source_contract_keeps_designated_delivery_email_out_of_persisted_model_fields(): void
    {
        $source = file_get_contents(app_path('Models/GvsuRegistrationAssignment.php'));
        self::assertIsString($source);
        self::assertStringNotContainsString("'designated_delivery_email'", $source);
        self::assertStringContainsString("'delivery_email_hmac_sha256'", $source);
    }

    public function test_canary_is_bound_to_one_exact_order_and_never_an_assignment_subset(): void
    {
        config()->set('services.gvsu_registration_bridge.mode', 'canary');
        config()->set('services.gvsu_registration_bridge.canary_order_ids', []);
        self::assertFalse(GvsuRegistrationBridgeConfig::mayEnqueueOrder(7, 91));
        self::assertFalse(GvsuRegistrationBridgeConfig::allowsCohort(7, 91));

        config()->set('services.gvsu_registration_bridge.canary_order_ids', [91]);
        self::assertTrue(GvsuRegistrationBridgeConfig::mayEnqueueOrder(7, 91));
        self::assertTrue(GvsuRegistrationBridgeConfig::allowsCohort(7, 91));
        self::assertFalse(GvsuRegistrationBridgeConfig::mayEnqueueOrder(7, 92));
        self::assertFalse(GvsuRegistrationBridgeConfig::mayEnqueueOrder(8, 91));

        $config = file_get_contents(config_path('services.php'));
        self::assertIsString($config);
        self::assertStringNotContainsString('canary_assignment_ids', $config);
    }

    public function test_all_checkin_writers_use_the_same_clearance_boundary_before_writes(): void
    {
        $public = file_get_contents(app_path('Services/Domain/CheckInList/CreateAttendeeCheckInService.php'));
        $authenticated = file_get_contents(app_path('Services/Application/Handlers/Attendee/CheckInAttendeeHandler.php'));
        self::assertIsString($public);
        self::assertIsString($authenticated);
        self::assertStringContainsString('GvsuRegistrationCheckInClearanceService', $public);
        self::assertStringContainsString('assertAttendeeCleared(', $public);
        self::assertLessThan(
            strpos($public, 'createCheckIn('),
            strpos($public, 'assertAttendeeCleared('),
        );
        self::assertStringContainsString('GvsuRegistrationCheckInClearanceService', $authenticated);
        self::assertStringContainsString('assertAttendeeCleared(', $authenticated);
    }

    public function test_current_state_validates_attendee_context_without_echoing_display_names_or_delivery_email(): void
    {
        $source = file_get_contents(app_path('Services/Domain/Registration/GvsuRegistrationBridgeService.php'));
        self::assertIsString($source);
        $currentState = substr(
            $source,
            (int) strpos($source, 'public function currentState'),
            (int) strpos($source, 'private function isCurrentPaidOrder') - (int) strpos($source, 'public function currentState'),
        );
        self::assertStringNotContainsString("'designated_delivery_email' =>", $currentState);
        self::assertStringNotContainsString("'respondent_display_name' =>", $currentState);
        self::assertStringNotContainsString("'attendee_display_name' =>", $currentState);
        self::assertStringContainsString("'attendee_display_name'", $currentState);
        self::assertStringContainsString("\$assignment->attendee_display_name !== \$candidate['attendee_display_name']", $currentState);
        self::assertStringContainsString("'respondent_identity_digest_sha256' =>", $currentState);
        self::assertStringContainsString("'designated_delivery_email'", $currentState);
    }

    public function test_assignment_migration_has_explicit_respondent_binding_and_encrypted_destination_with_attendee_uniqueness(): void
    {
        $migration = file_get_contents(database_path('migrations/2026_09_22_000001_create_gvsu_registration_assignments_table.php'));
        self::assertIsString($migration);
        self::assertStringContainsString("string('attendee_display_name', 200)", $migration);
        self::assertStringContainsString("string('respondent_display_name', 200)", $migration);
        self::assertStringContainsString("string('respondent_route', 16)", $migration);
        self::assertStringContainsString("char('respondent_identity_digest_sha256', 64)", $migration);
        self::assertStringContainsString("longText('delivery_destination_ciphertext')", $migration);
        self::assertStringNotContainsString('designated_delivery_email', $migration);
        self::assertStringContainsString("unique(['event_id', 'attendee_id']", $migration);
        self::assertStringContainsString("unique('assignment_id')", $migration);
    }
}
