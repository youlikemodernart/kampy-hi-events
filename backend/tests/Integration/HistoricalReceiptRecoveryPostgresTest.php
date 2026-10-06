<?php

namespace Tests\Integration;

use Carbon\Carbon;
use HiEvents\Repository\Eloquent\HistoricalReceiptRecoveryRepository as Recovery;
use HiEvents\Repository\Eloquent\RespondentConfirmationRepository as Challenges;
use HiEvents\Services\Domain\Registration\GvsuRegistrationBridgePortalClient;
use HiEvents\Services\Domain\Registration\HistoricalReceiptValidator as V;
use HiEvents\Services\Domain\Registration\RespondentConfirmationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\Support\HistoricalReceiptFixture;
use Tests\Support\RespondentConfirmationFixture as Fixture;
use Tests\TestCase;

class HistoricalReceiptRecoveryPostgresTest extends TestCase
{
    private array $manifest;

    private array $rows;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('KAMP_RESPONDENT_DISPOSABLE') !== '1') {
            $this->markTestSkipped('Disposable PostgreSQL required');
        }
        Fixture::configure();
        Fixture::reset();
        Carbon::setTestNow('2026-10-06T02:00:00Z');
        Http::preventStrayRequests();
        Mail::fake();
        [$this->manifest, $this->rows] = HistoricalReceiptFixture::bundle();
        HistoricalReceiptFixture::configure($this->manifest);
        HistoricalReceiptFixture::prepareNative($this->rows);
        $portal = \Mockery::mock(GvsuRegistrationBridgePortalClient::class);
        $portal->shouldReceive('historicalAbsence')->andReturn(true)->byDefault();
        $portal->shouldNotReceive('provision');
        app()->instance(GvsuRegistrationBridgePortalClient::class, $portal);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function admit(): Recovery
    {
        $repo = new Recovery;
        $repo->import($this->manifest, $this->rows, fn ($id) => $repo->preflight($id), true);

        return $repo;
    }

    public function test_default_dry_run_and_disabled_import_have_no_writes_or_send(): void
    {
        config()->set('historical-receipt-recovery.import_enabled', false);
        self::assertTrue((new Recovery)->import($this->manifest, $this->rows, fn () => true)['dry_run']);
        self::assertSame(0, DB::table('historical_receipt_recovery_cohorts')->count());
        try {
            (new Recovery)->import($this->manifest, $this->rows, fn () => true, true);
            self::fail();
        } catch (\HiEvents\Exceptions\ResourceConflictException) {
        }
        config()->set('respondent-confirmation.enabled', false);
        app(RespondentConfirmationService::class)->request('order_11');
        self::assertSame(0, DB::table('respondent_confirmation_challenges')->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_sealed_receipt_authority_verify_then_create_all_siblings_and_exact_replay(): void
    {
        $recovery = $this->admit();
        self::assertSame('historical_receipt_v1', $recovery->authority(11)['type']);
        self::assertFalse(DB::table('order_purchase_contacts')->where('order_id', 11)->exists());
        $stored = DB::table('order_receipt_recovery_evidence')->first();
        self::assertStringNotContainsString('buyer@example.test', $stored->evidence_encrypted);
        $repo = new Challenges;
        $code = $repo->issue('order_11')->token;
        $service = app(RespondentConfirmationService::class);
        self::assertFalse($service->confirm('order_11', $code, Fixture::payload()));
        self::assertNull($repo->verify('order_12', $code));
        self::assertNull($repo->verify('order_11', str_repeat('0', 64)));
        self::assertSame(1, DB::table('respondent_confirmation_challenges')->value('attempts'));
        self::assertCount(2, $repo->verify('order_11', $code));
        self::assertCount(2, $repo->verify('order_11', $code));
        self::assertTrue($service->confirm('order_11', $code, Fixture::payload()));
        self::assertTrue($service->confirm('order_11', $code, Fixture::payload()));
        $different = Fixture::payload();
        $different[0]['email'] = 'other@example.test';
        self::assertFalse($service->confirm('order_11', $code, $different));
        self::assertSame(2, DB::table('gvsu_registration_assignments')->where('recovery_evidence_id', $stored->id)->count());
        self::assertSame(1, DB::table('order_effect_outbox')->where('effect_type', 'GVSU_REGISTRATION_BRIDGE')->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_invitation_later_click_resume_and_earlier_historical_authority_cutoff(): void
    {
        config()->set('respondent-confirmation.invitation_enabled', true);
        $this->manifest['scope']['valid_until'] = '2026-10-10T20:00:00Z';
        $this->manifest['scope']['purge_after'] = '2026-10-10T20:00:00Z';
        config()->set('historical-receipt-recovery.approved_manifest_digest', hash('sha256', V::canonical($this->manifest)));
        $this->admit();
        $repo = new Challenges;
        $token = $repo->issue('order_11', true)->token;
        self::assertSame('2026-10-10 20:00:00', DB::table('respondent_confirmation_challenges')->value('expires_at'));
        $cohort = DB::table('historical_receipt_recovery_cohorts')->first();
        Carbon::setTestNow('2026-10-06T18:00:00Z');
        self::assertFalse($repo->invitationContext('order_11', $token)['confirmed']);
        self::assertTrue(app(RespondentConfirmationService::class)->confirm('order_11', $token, Fixture::payload()));
        Carbon::setTestNow('2026-10-08T18:00:00Z');
        self::assertTrue($repo->invitationContext('order_11', $token)['confirmed']);
        self::assertTrue(app(RespondentConfirmationService::class)->confirm('order_11', $token, Fixture::payload()));
        $assignments = DB::table('gvsu_registration_assignments')->get();
        Carbon::setTestNow('2026-10-10T20:00:00Z');
        self::assertNull($repo->invitationContext('order_11', $token));
        self::assertFalse(app(RespondentConfirmationService::class)->confirm('order_11', $token, Fixture::payload()));
        self::assertEquals($cohort, DB::table('historical_receipt_recovery_cohorts')->first());
        self::assertEquals($assignments, DB::table('gvsu_registration_assignments')->get());
        self::assertSame(1, DB::table('order_effect_outbox')->where('effect_type', 'GVSU_REGISTRATION_BRIDGE')->count());
        Carbon::setTestNow('2026-10-08T18:00:00Z');
        DB::table('historical_receipt_recovery_cohorts')->update(['revoked_at' => now()]);
        self::assertNull($repo->invitationContext('order_11', $token));
        self::assertFalse(app(RespondentConfirmationService::class)->confirm('order_11', $token, Fixture::payload()));
        self::assertEquals($assignments, DB::table('gvsu_registration_assignments')->get());
    }

    public function test_reissue_invalidates_verified_code_and_expiry_and_shared_attempt_limit(): void
    {
        $this->admit();
        $repo = new Challenges;
        $old = $repo->issue('order_11')->token;
        self::assertNotNull($repo->verify('order_11', $old));
        Carbon::setTestNow(now()->addSeconds(61));
        $code = $repo->issue('order_11')->token;
        self::assertNull($repo->verify('order_11', $old));
        for ($i = 0; $i < 4; $i++) {
            self::assertFalse(app(RespondentConfirmationService::class)->confirm('order_11', str_repeat('0', 64), Fixture::payload()));
        }
        self::assertNull($repo->verify('order_11', $code));
        Carbon::setTestNow(now()->addSeconds(61));
        $code = $repo->issue('order_11')->token;
        self::assertNotNull($repo->verify('order_11', $code));
        Carbon::setTestNow(now()->addMinutes(16));
        self::assertFalse(app(RespondentConfirmationService::class)->confirm('order_11', $code, Fixture::payload()));
        self::assertSame(0, DB::table('gvsu_registration_assignments')->count());
    }

    public function test_immutability_roles_retention_tombstones_and_no_reimport(): void
    {
        // PostgreSQL's independent wall clock must also be past the purge deadline.
        $this->manifest['scope']['selected_at'] = '2026-10-05T23:00:00Z';
        $this->manifest['scope']['valid_until'] = '2026-10-06T01:00:00Z';
        $this->manifest['scope']['purge_after'] = '2026-10-06T01:00:00Z';
        config()->set('historical-receipt-recovery.approved_manifest_digest', hash('sha256', V::canonical($this->manifest)));
        Carbon::setTestNow('2026-10-06T00:00:00Z');
        $recovery = $this->admit();
        DB::unprepared("DO $$ BEGIN IF NOT EXISTS(SELECT FROM pg_roles WHERE rolname='historical_test_intake') THEN CREATE ROLE historical_test_intake; END IF; END $$; GRANT USAGE ON SCHEMA public TO historical_test_intake; GRANT SELECT ON historical_receipt_recovery_cohorts,order_receipt_recovery_evidence TO historical_test_intake;");
        try {
            DB::transaction(function () {
                DB::statement('SET LOCAL ROLE historical_test_intake');
                DB::table('order_receipt_recovery_evidence')->update(['evidence_encrypted' => 'swapped']);
            });
            self::fail();
        } catch (\Illuminate\Database\QueryException $e) {
            self::assertSame('42501', $e->errorInfo[0]);
        }
        foreach ([fn () => DB::table('order_receipt_recovery_evidence')->update(['evidence_commitment' => str_repeat('a', 64)]), fn () => DB::table('historical_receipt_recovery_cohorts')->update(['exact_order_count' => 2]), fn () => DB::table('order_receipt_recovery_evidence')->delete()] as $mutation) {
            try {
                DB::transaction($mutation);
                self::fail();
            } catch (\Illuminate\Database\QueryException) {
                self::assertTrue(true);
            }
        }
        self::assertSame(0, $recovery->purge());
        Carbon::setTestNow('2026-10-18T21:00:00Z');
        self::assertNull($recovery->authority(11));
        self::assertSame(1, $recovery->purge());
        self::assertNull(DB::table('order_receipt_recovery_evidence')->value('evidence_encrypted'));
        self::assertSame(1, DB::table('order_receipt_recovery_evidence')->count());
        Carbon::setTestNow('2026-10-06T00:00:00Z');
        try {
            $this->admit();
            self::fail();
        } catch (\Illuminate\Database\QueryException) {
            self::assertTrue(true);
        }
    }

    public function test_finite_metadata_retention_preserves_records_and_blocks_replay_without_tombstones(): void
    {
        $recovery = $this->admit();
        $challenges = new Challenges;
        $code = $challenges->issue('order_11')->token;
        self::assertNotNull($challenges->verify('order_11', $code));
        self::assertTrue(app(RespondentConfirmationService::class)->confirm('order_11', $code, Fixture::payload()));
        \Illuminate\Support\Facades\Schema::table('events', function ($table) {
            $table->timestamp('start_date')->nullable();
            $table->string('timezone')->default('UTC');
        });
        DB::table('events')->update(['start_date' => '2026-10-17 20:00:00']);
        $ordinary = (array) DB::table('gvsu_registration_assignments')->first();
        unset($ordinary['id']);
        $ordinary = array_replace($ordinary, ['order_id' => 12, 'attendee_id' => 121, 'attendee_public_id' => 'invented_12_1', 'assignment_id' => 'gra_ordinary_retention_test', 'respondent_id' => 'grr_ordinary_retention_test', 'recovery_evidence_id' => null]);
        DB::table('gvsu_registration_assignments')->insert($ordinary);
        $bridge = app(\HiEvents\Services\Domain\Registration\GvsuRegistrationBridgeService::class);
        $candidate = function ($a) {
            return ['event_id' => '7', 'order_id' => (string) $a->order_id, 'attendee_id' => (string) $a->attendee_id, 'public_ticket_id' => $a->attendee_public_id, 'respondent_id' => $a->respondent_id, 'assignment_id' => $a->assignment_id, 'attendee_display_name' => $a->attendee_display_name, 'respondent_identity_digest_sha256' => $a->respondent_identity_digest_sha256, 'designated_delivery_email' => $a->delivery_destination_ciphertext];
        };
        $historicalCandidate = $candidate(\HiEvents\Models\GvsuRegistrationAssignment::where('order_id', 11)->first());
        $ordinaryCandidate = $candidate(\HiEvents\Models\GvsuRegistrationAssignment::where('order_id', 12)->first());
        self::assertSame('current', $bridge->currentState($historicalCandidate)['status']);
        self::assertSame('current', $bridge->currentState($ordinaryCandidate)['status']);
        $orders = DB::table('orders')->orderBy('id')->get()->toJson();
        $assignments = DB::table('gvsu_registration_assignments')->orderBy('id')->get()->map(fn ($a) => (array) $a)->all();
        $cohort = (array) DB::table('historical_receipt_recovery_cohorts')->first();
        unset($cohort['id']);
        $cohort['sealed_at'] = null;
        $outbox = DB::table('order_effect_outbox')->orderBy('id')->get()->toJson();
        $definition = DB::selectOne("SELECT pg_get_functiondef('guard_historical_receipt_records()'::regprocedure) AS ddl")->ddl;
        // Disposable database only: substitute the trigger's clock, never disable guards.
        // Production has no configurable clock or expiry-bypass setting.
        $clock = function (string $time) use ($definition) {
            Carbon::setTestNow($time);
            DB::unprepared(str_replace('CURRENT_TIMESTAMP', "TIMESTAMPTZ '$time'", $definition));
        };
        $reject = function (callable $operation) {
            try {
                DB::transaction($operation);
                self::fail('Retention guard must reject');
            } catch (\Illuminate\Database\QueryException) {
                self::assertTrue(true);
            }
        };
        try {
            $clock('2027-01-15T20:00:00Z');
            $reject(function () {
                DB::table('respondent_confirmation_challenges')->update(['authority_id' => null, 'authority_commitment' => null]);
                DB::table('order_receipt_recovery_evidence')->delete();
            });
            self::assertNotNull(DB::table('order_receipt_recovery_evidence')->value('evidence_encrypted'));
            self::assertNotNull(DB::table('respondent_confirmation_challenges')->value('authority_id'));
            $clock('2026-10-17T20:00:00Z');
            self::assertSame(1, $recovery->purge());
            self::assertNull($recovery->authority(11));
            $reject(fn () => DB::table('historical_receipt_recovery_cohorts')->insert($cohort));
            $clock('2027-01-15T19:59:59Z');
            self::assertSame(['evidence_deleted' => 0, 'cohorts_deleted' => 0], $recovery->purgeMetadata());
            $reject(fn () => DB::table('order_receipt_recovery_evidence')->delete());
            $reject(fn () => DB::table('historical_receipt_recovery_cohorts')->delete());
            $clock('2027-01-15T20:00:00Z');
            // Existing references cannot be silently orphaned by direct SQL.
            $reject(fn () => DB::table('order_receipt_recovery_evidence')->delete());
            DB::table('respondent_confirmation_challenges')->update(['expires_at' => now()->addMinute()]);
            $reject(fn () => $recovery->purgeMetadata());
            self::assertNotNull(DB::table('respondent_confirmation_challenges')->value('authority_id'));
            self::assertSame($assignments, DB::table('gvsu_registration_assignments')->orderBy('id')->get()->map(fn ($a) => (array) $a)->all());
            DB::table('respondent_confirmation_challenges')->update(['expires_at' => now()]);
            self::assertSame(['evidence_deleted' => 1, 'cohorts_deleted' => 1], $recovery->purgeMetadata());
            self::assertSame(['evidence_deleted' => 0, 'cohorts_deleted' => 0], $recovery->purgeMetadata());
            self::assertSame(1, DB::table('respondent_confirmation_challenges')->count());
            self::assertNull(DB::table('respondent_confirmation_challenges')->value('authority_id'));
            self::assertNull(DB::table('respondent_confirmation_challenges')->value('authority_commitment'));
            foreach ($assignments as &$assignment) {
                if ($assignment['recovery_evidence_id'] !== null) {
                    $assignment['recovery_evidence_id'] = null;
                    $assignment['status'] = 'expired';
                }
            }
            unset($assignment);
            self::assertSame($assignments, DB::table('gvsu_registration_assignments')->orderBy('id')->get()->map(fn ($a) => (array) $a)->all());
            self::assertSame('blocked', $bridge->currentState($historicalCandidate)['status']);
            self::assertSame('current', $bridge->currentState($ordinaryCandidate)['status']);
            config()->set('services.gvsu_registration_bridge.enabled', true);
            self::assertTrue($bridge->provisionCompletedOrder(11));
            $reject(fn () => DB::table('gvsu_registration_assignments')->where('order_id', 11)->update(['status' => 'bound']));
            try {
                $bridge->bindRespondent(11, 111, 'Invented Attendee 1', 'Invented Adult', 'adult', 'adult@example.test', null, true);
                self::fail('Expired assignment must not be rebound');
            } catch (\HiEvents\Exceptions\ResourceConflictException) {
                self::assertTrue(true);
            }
            self::assertSame($orders, DB::table('orders')->orderBy('id')->get()->toJson());
            self::assertSame($outbox, DB::table('order_effect_outbox')->orderBy('id')->get()->toJson());
            $reject(fn () => DB::table('historical_receipt_recovery_cohorts')->insert($cohort));
            try {
                $this->admit();
                self::fail('Import must remain closed without tombstones');
            } catch (\HiEvents\Exceptions\ResourceConflictException) {
                self::assertSame(0, DB::table('historical_receipt_recovery_cohorts')->count());
            }
        } finally {
            DB::unprepared($definition);
        }
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_changed_sibling_context_wrong_authority_and_key_loss_block_without_replacement(): void
    {
        $this->admit();
        $repo = new Challenges;
        $code = $repo->issue('order_11')->token;
        self::assertNotNull($repo->verify('order_11', $code));
        DB::table('attendees')->where('id', 111)->update(['first_name' => 'Changed']);
        self::assertFalse(app(RespondentConfirmationService::class)->confirm('order_11', $code, Fixture::payload()));
        DB::table('attendees')->where('id', 111)->update(['first_name' => 'Invented']);
        DB::table('respondent_confirmation_challenges')->update(['authority_type' => 'checkout_anchor_v1']);
        self::assertFalse(app(RespondentConfirmationService::class)->confirm('order_11', $code, Fixture::payload()));
        DB::table('respondent_confirmation_challenges')->update(['authority_type' => 'historical_receipt_v1']);
        config()->set('historical-receipt-recovery.encryption_keys', []);
        try {
            $repo->verify('order_11', $code);
            self::fail();
        } catch (\HiEvents\Exceptions\ResourceConflictException) {
            self::assertTrue(true);
        }
        self::assertSame(0, DB::table('gvsu_registration_assignments')->count());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_import_rechecks_siblings_after_exact_absence_and_rejects_contact_commitment_changes(): void
    {
        $repo = new Recovery;
        try {
            $repo->import($this->manifest, $this->rows, function ($id) use ($repo) {
                $before = $repo->preflight($id);
                DB::table('attendees')->where('id', 112)->update(['status' => 'CANCELLED']);

                return $before;
            }, true);
            self::fail();
        } catch (\HiEvents\Exceptions\ResourceConflictException) {
            self::assertTrue(true);
        }
        self::assertSame(0, DB::table('historical_receipt_recovery_cohorts')->count());
        $changed = $this->rows;
        $changed[0]['order']['email'] = 'substituted@example.test';
        $changed[0]['evidence']['search']['recipient'] = 'substituted@example.test';
        $changed[0]['evidence']['messages'][0]['To'][0]['Email'] = 'substituted@example.test';
        try {
            $repo->import($this->manifest, $changed, fn () => true);
            self::fail();
        } catch (\HiEvents\Exceptions\ResourceConflictException) {
            self::assertTrue(true);
        }
    }

    public function test_subset_keeps_complete_continuity_without_admitting_excluded_evidence(): void
    {
        $excluded = ['order_id' => 13, 'pi' => 'pi_synthetic13', 'charge' => 'ch_synthetic13', 'message_id' => '33333333-3333-4333-8333-333333333333'];
        $this->manifest['reconstructed_order_ids'][] = 13;
        $this->manifest['reconstructed_associations'][] = $excluded;
        $this->manifest['excluded_orders'] = [['order_id' => 13, 'reason' => 'receipt_ambiguous']];
        $this->manifest['original_aggregate_commitment'] = hash('sha256', implode("\n", array_map(fn ($a) => hash('sha256', implode('|', [$a['order_id'], $a['pi'], $a['charge'], $a['message_id']])), $this->manifest['reconstructed_associations'])));
        HistoricalReceiptFixture::configure($this->manifest);
        self::assertSame(1, (new Recovery)->import($this->manifest, $this->rows, fn () => true)['count']);
        $original = $this->manifest;
        foreach (['missing_exclusion', 'aggregate', 'accepted_association', 'ambiguous_admitted'] as $failure) {
            $manifest = $original;
            $rows = $this->rows;
            if ($failure === 'missing_exclusion') {
                $manifest['excluded_orders'] = [];
            }
            if ($failure === 'aggregate') {
                $manifest['original_aggregate_commitment'] = str_repeat('0', 64);
            }
            if ($failure === 'accepted_association') {
                $manifest['reconstructed_associations'][0]['pi'] = 'pi_other';
                $manifest['original_aggregate_commitment'] = hash('sha256', implode("\n", array_map(fn ($a) => hash('sha256', implode('|', [$a['order_id'], $a['pi'], $a['charge'], $a['message_id']])), $manifest['reconstructed_associations'])));
            }
            if ($failure === 'ambiguous_admitted') {
                $rows[0]['evidence']['messages'][] = $rows[0]['evidence']['messages'][0];
                $rows[0]['evidence']['search']['total'] = 2;
                $rows[0]['evidence']['search']['message_ids'][] = $rows[0]['evidence']['search']['message_ids'][0];
            }
            HistoricalReceiptFixture::configure($manifest);
            try {
                (new Recovery)->import($manifest, $rows, fn () => self::fail('No preflight on invalid evidence'), true);
                self::fail($failure);
            } catch (\HiEvents\Exceptions\ResourceConflictException) {
                self::assertSame(0, DB::table('order_receipt_recovery_evidence')->count());
            }
        }
        HistoricalReceiptFixture::configure($original);
        $this->admit();
        self::assertSame([11], DB::table('order_receipt_recovery_evidence')->pluck('order_id')->all());
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_environment_key_maps_and_missing_keys_fail_closed_before_preflight(): void
    {
        $env = \Illuminate\Support\Env::getRepository();
        $name = 'KAMP_HISTORICAL_RECEIPT_ENCRYPTION_KEYS';
        $previous = $env->get($name);
        try {
            foreach (['', 'not-json', '[]', '{"v1":"short"}', json_encode(['v1' => base64_encode(str_repeat('x', 31))]), json_encode(['bad version' => base64_encode(str_repeat('x', 32))])] as $invalid) {
                $env->set($name, $invalid);
                $config = require base_path('config/historical-receipt-recovery.php');
                self::assertSame([], $config['encryption_keys']);
            }
            $env->set($name, json_encode(['v1' => base64_encode(str_repeat('x', 32)), 'v2' => base64_encode(str_repeat('y', 32))]));
            $config = require base_path('config/historical-receipt-recovery.php');
            self::assertSame(['v1' => str_repeat('x', 32), 'v2' => str_repeat('y', 32)], $config['encryption_keys']);
        } finally {
            if ($previous === null) {
                $env->clear($name);
            } else {
                $env->set($name, $previous);
            }
        }
        foreach (['encryption_keys', 'integrity_keys'] as $keyMap) {
            HistoricalReceiptFixture::configure($this->manifest);
            config()->set('historical-receipt-recovery.'.$keyMap, []);
            try {
                (new Recovery)->import($this->manifest, $this->rows, fn () => self::fail('Missing custody must stop before preflight'), true);
                self::fail();
            } catch (\HiEvents\Exceptions\ResourceConflictException) {
                self::assertSame(0, DB::table('historical_receipt_recovery_cohorts')->count());
            }
        }
        HistoricalReceiptFixture::configure($this->manifest);
        config()->set('historical-receipt-recovery.encryption_keys', ['test1' => str_repeat('i', 32)]);
        try {
            (new Recovery)->import($this->manifest, $this->rows, fn () => self::fail('Keys must be separate'), true);
            self::fail();
        } catch (\HiEvents\Exceptions\ResourceConflictException) {
            self::assertSame(0, DB::table('historical_receipt_recovery_cohorts')->count());
        }
        HistoricalReceiptFixture::configure($this->manifest);
        config()->set('historical-receipt-recovery.encryption_key_version', 'test.1');
        config()->set('historical-receipt-recovery.integrity_key_version', 'test.1');
        config()->set('historical-receipt-recovery.encryption_keys', ['test.1' => str_repeat('e', 32)]);
        config()->set('historical-receipt-recovery.integrity_keys', ['test.1' => str_repeat('i', 32)]);
        self::assertSame('historical_receipt_v1', $this->admit()->authority(11)['type']);
        Mail::assertNothingSent();
        Http::assertNothingSent();
    }

    public function test_unknown_preflight_and_revocation_and_changed_manifest_fail_closed(): void
    {
        try {
            (new Recovery)->import($this->manifest, $this->rows, fn () => false, true);
            self::fail();
        } catch (\HiEvents\Exceptions\ResourceConflictException) {
        }
        self::assertSame(0, DB::table('order_receipt_recovery_evidence')->count());
        $recovery = $this->admit();
        $repo = new Challenges;
        $code = $repo->issue('order_11')->token;
        self::assertNotNull($repo->verify('order_11', $code));
        DB::table('historical_receipt_recovery_cohorts')->update(['revoked_at' => now()]);
        self::assertNull($recovery->authority(11));
        self::assertFalse(app(RespondentConfirmationService::class)->confirm('order_11', $code, Fixture::payload()));
        self::assertSame(0, DB::table('gvsu_registration_assignments')->count());
        $changed = $this->manifest;
        $changed['order_ids'] = [12];
        try {
            $recovery->import($changed, $this->rows, fn () => true);
            self::fail();
        } catch (\HiEvents\Exceptions\ResourceConflictException) {
            self::assertTrue(true);
        }
    }
}
