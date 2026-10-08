<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Registration;

use Tests\TestCase;

class DirectorRosterSourceContractTest extends TestCase
{
    public function test_snapshot_is_an_allowlisted_read_without_waiver_or_contact_payloads(): void
    {
        $sql = file_get_contents(database_path('roster/director_roster_v1.sql'));
        $snapshot = substr($sql, strpos($sql, 'CREATE FUNCTION public.kampy_roster_event_snapshot_v1'), strpos($sql, 'CREATE FUNCTION public.kampy_roster_contact_v1') - strpos($sql, 'CREATE FUNCTION public.kampy_roster_event_snapshot_v1'));
        self::assertStringContainsString('p_account,p_event', $snapshot);
        self::assertStringContainsString('a.event_id=p_event', $snapshot);
        self::assertStringContainsString('a.deleted_at IS NULL AND o.deleted_at IS NULL', $snapshot);
        self::assertStringContainsString('c.deleted_at IS NULL', $snapshot);
        foreach (['a.email', 'o.email', 'question_answers', 'delivery_destination', 'ip_address', 'INSERT INTO', 'UPDATE ', 'DELETE FROM'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $snapshot);
        }
    }

    public function test_current_assignment_authority_uses_only_binding_and_retention_metadata(): void
    {
        $sql = file_get_contents(database_path('roster/director_roster_v1.sql'));
        foreach (['"assignmentExists"', '"assignmentBindingValid"', '"assignmentAuthorityEligible"', 'g.assignment_count=1', 'h.sealed_at IS NOT NULL', 'h.revoked_at IS NULL', 'h.valid_until>statement_timestamp()', 'e.purged_at IS NULL', 'e.id=g.recovery_evidence_id', 'e.order_id=a.order_id', 'h.account_id=p_account'] as $required) {
            self::assertStringContainsString($required, $sql);
        }
        foreach (['evidence_encrypted', 'evidence_commitment', 'scope_json', 'respondent_confirmation_challenges', 'kampy_registration_respondent_tokens'] as $forbidden) {
            self::assertStringNotContainsString($forbidden, $sql);
        }
    }

    public function test_reader_is_dormant_execute_only_with_no_seed_or_login(): void
    {
        $sql = file_get_contents(database_path('roster/director_roster_v1.sql'));
        self::assertStringContainsString('CREATE ROLE kampy_native_roster_reader NOLOGIN NOINHERIT', $sql);
        self::assertStringContainsString('SECURITY DEFINER SET search_path=pg_catalog', $sql);
        self::assertStringContainsString('REVOKE ALL ON FUNCTION public.kampy_roster_event_snapshot_v1', $sql);
        self::assertStringNotContainsString('INSERT INTO', $sql);
        self::assertStringNotContainsString('TO kampy_native_roster_reader WITH', $sql);
        self::assertStringNotContainsString('GRANT SELECT ON public.attendees TO kampy_native_roster_reader', $sql);
    }
}
