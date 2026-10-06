<?php

namespace Tests\Integration;

use Carbon\Carbon;
use HiEvents\Repository\Eloquent\HistoricalReceiptRecoveryRepository as Recovery;
use HiEvents\Repository\Eloquent\RespondentConfirmationRepository as Challenges;
use HiEvents\Services\Domain\Registration\GvsuRegistrationBridgePortalClient;
use HiEvents\Services\Domain\Registration\HistoricalReceiptValidator as V;
use HiEvents\Services\Domain\Registration\RespondentConfirmationService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
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
        config()->set('historical-receipt-recovery', ['enabled' => true, 'import_enabled' => true,
            'approved_manifest_digest' => hash('sha256', V::canonical($this->manifest)), 'encryption_key_version' => 'test1', 'integrity_key_version' => 'test1',
            'encryption_keys' => ['test1' => str_repeat('e', 32)], 'integrity_keys' => ['test1' => str_repeat('i', 32)]]);
        Schema::table('orders', fn (Blueprint $t) => $t->string('public_id')->nullable());
        Schema::table('events', function (Blueprint $t) {
            $t->integer('account_id')->default(1);
            $t->integer('organizer_id')->default(2);
        });
        Schema::create('stripe_payments', function (Blueprint $t) {
            $t->id();
            $t->integer('order_id');
            $t->string('payment_intent_id');
            $t->string('charge_id');
            $t->string('connected_account_id');
            $t->string('stripe_platform')->nullable();
            $t->softDeletes();
        });
        DB::table('order_purchase_contacts')->where('order_id', 11)->delete();
        DB::table('orders')->where('id', 11)->update(['public_id' => 'PUBLIC-SYNTHETIC-11']);
        DB::table('stripe_payments')->insert($this->rows[0]['evidence']['native_payments'][0]);
        DB::table('order_effect_outbox')->insert($this->rows[0]['evidence']['native_outbox'][0] + ['delivery_id' => 'synthetic_initial_11', 'business_key' => 'synthetic_initial_11', 'effect_type' => 'EMAIL', 'available_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
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
        $this->manifest['scope']['valid_until'] = '2026-10-06T00:30:00Z';
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
