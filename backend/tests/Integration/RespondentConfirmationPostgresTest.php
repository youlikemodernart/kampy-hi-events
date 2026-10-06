<?php

namespace Tests\Integration;

use HiEvents\Repository\Eloquent\RespondentConfirmationRepository;
use HiEvents\Services\Domain\Registration\RespondentConfirmationRetention;
use HiEvents\Services\Domain\Registration\RespondentConfirmationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Tests\Support\RespondentConfirmationFixture as Fixture;
use Tests\TestCase;

class RespondentConfirmationPostgresTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('KAMP_RESPONDENT_DISPOSABLE') !== '1') {
            $this->markTestSkipped('Run scripts/test-respondent-confirmation.sh');
        }
        Fixture::configure();
        Fixture::reset();
    }

    private function parallel(array $actions): array
    {
        $dir = sys_get_temp_dir().'/respondent-parallel-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        DB::disconnect('pgsql');
        $pids = [];
        try {
            foreach ($actions as $i => $action) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('fork failed');
                }
                if ($pid === 0) {
                    DB::purge('pgsql');
                    DB::statement("SET application_name = 'respondent_parallel_{$i}'");
                    while (! file_exists($dir.'/start')) {
                        usleep(1000);
                    }
                    try {
                        $result = $action($dir);
                        file_put_contents($dir.'/'.$i, json_encode(['result' => $result]));
                        exit(0);
                    } catch (\Throwable $e) {
                        file_put_contents($dir.'/'.$i, json_encode(['error' => get_class($e)]));
                        exit(1);
                    }
                }
                $pids[] = $pid;
            }
            touch($dir.'/start');
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
                self::assertSame(0, pcntl_wexitstatus($status), 'child returned failure');
            }
            $results = [];
            foreach (array_keys($actions) as $i) {
                $results[] = json_decode(file_get_contents($dir.'/'.$i), true, flags: JSON_THROW_ON_ERROR)['result'];
            }

            return $results;
        } finally {
            DB::purge('pgsql');
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function test_parallel_two_order_shared_destination_cooldown_and_hour_budget(): void
    {
        $repo = new RespondentConfirmationRepository;
        self::assertSame(1, count(array_filter($this->parallel([fn () => $repo->issue('order_11') !== null, fn () => $repo->issue('order_12') !== null]))));
        // Age only cooldown, preserving hourly budget; alternate orders in parallel at the fifth request.
        for ($i = 0; $i < 3; $i++) {
            DB::table('respondent_confirmation_destinations')->update(['last_requested_at' => now()->subSeconds(61)]);
            self::assertNotNull($repo->issue($i % 2 ? 'order_12' : 'order_11'));
        }
        DB::table('respondent_confirmation_destinations')->update(['last_requested_at' => now()->subSeconds(61)]);
        self::assertSame(1, count(array_filter($this->parallel([fn () => $repo->issue('order_11') !== null, fn () => $repo->issue('order_12') !== null]))));
        DB::table('respondent_confirmation_destinations')->update(['last_requested_at' => now()->subSeconds(61)]);
        self::assertSame([false, false], $this->parallel([fn () => $repo->issue('order_11') !== null, fn () => $repo->issue('order_12') !== null]));
        self::assertSame(5, DB::table('respondent_confirmation_challenges')->count());
        self::assertSame(5, DB::table('respondent_confirmation_destinations')->value('request_count'));
    }

    public function test_parallel_consumption_commits_exactly_one_bridge_outbox_and_complete_assignments(): void
    {
        $code = (new RespondentConfirmationRepository)->issue('order_11')->token;
        self::assertNotNull((new RespondentConfirmationRepository)->verify('order_11', $code));
        $confirm = fn () => app(RespondentConfirmationService::class)->confirm('order_11', $code, Fixture::payload());
        self::assertSame([true, true], $this->parallel([$confirm, $confirm]));
        self::assertSame(2, DB::table('gvsu_registration_assignments')->count());
        self::assertSame(1, DB::table('order_effect_outbox')->count());
        self::assertSame('GVSU_REGISTRATION_BRIDGE', DB::table('order_effect_outbox')->value('effect_type'));
        self::assertNotNull(DB::table('respondent_confirmation_challenges')->value('consumed_at'));
    }

    public function test_invitation_parallel_commit_and_resumption_reject_corrected_assignments(): void
    {
        config()->set('respondent-confirmation.invitation_enabled', true);
        $repo = new RespondentConfirmationRepository;
        $token = $repo->issue('order_11', true)->token;
        $before = DB::table('respondent_confirmation_challenges')->first();
        self::assertNull($before->verified_at);
        self::assertFalse($repo->invitationContext('order_11', $token)['confirmed']);
        self::assertEquals($before, DB::table('respondent_confirmation_challenges')->first());
        $confirm = fn () => app(RespondentConfirmationService::class)->confirm('order_11', $token, Fixture::payload());
        self::assertSame([true, true], $this->parallel([$confirm, $confirm]));
        self::assertSame(2, DB::table('gvsu_registration_assignments')->count());
        self::assertSame(1, DB::table('order_effect_outbox')->count());
        self::assertTrue($repo->invitationContext('order_11', $token)['confirmed']);
        config()->set('respondent-confirmation.invitation_enabled', false);
        self::assertNull($repo->invitationContext('order_11', $token));
        self::assertFalse($confirm());
        config()->set('respondent-confirmation.invitation_enabled', true);
        DB::table('gvsu_registration_assignments')->where('attendee_id', 112)->update(['respondent_display_name' => 'Corrected Guardian']);
        self::assertNull($repo->invitationContext('order_11', $token));
    }

    public function test_invitation_event_window_and_cancellation_block_resume_without_changing_committed_work(): void
    {
        config()->set('respondent-confirmation.invitation_enabled', true);
        $repo = new RespondentConfirmationRepository;
        $issuedAt = \Carbon\Carbon::parse('2026-10-06T02:00:00Z');
        $this->travelTo($issuedAt);
        try {
            $token = $repo->issue('order_11', true)->token;
            $confirm = fn () => app(RespondentConfirmationService::class)->confirm('order_11', $token, Fixture::payload());
            self::assertFalse(app(RespondentConfirmationService::class)->confirm('order_12', $token, Fixture::payload()));
            self::assertSame('2026-10-17 20:00:00', DB::table('respondent_confirmation_challenges')->value('expires_at'));
            $this->travelTo($issuedAt->copy()->addHours(8));
            self::assertFalse($repo->invitationContext('order_11', $token)['confirmed']);
            self::assertTrue($confirm());
            $this->travelTo($issuedAt->copy()->addDays(2));
            self::assertSame(0, app(RespondentConfirmationRetention::class)->purge()['challenges_deleted']);
            self::assertTrue($repo->invitationContext('order_11', $token)['confirmed']);
            self::assertTrue($confirm());
            $assignments = DB::table('gvsu_registration_assignments')->orderBy('attendee_id')->get();
            $outbox = DB::table('order_effect_outbox')->get();
            $this->travelTo(\Carbon\Carbon::parse('2026-10-17T19:59:59Z'));
            self::assertTrue($repo->invitationContext('order_11', $token)['confirmed']);
            self::assertTrue($confirm());
            $this->travel(1)->seconds();
            self::assertNull($repo->invitationContext('order_11', $token));
            self::assertFalse($confirm());
            self::assertNull($repo->issue('order_12', true));
            self::assertEquals($assignments, DB::table('gvsu_registration_assignments')->orderBy('attendee_id')->get());
            self::assertEquals($outbox, DB::table('order_effect_outbox')->get());
            $this->travelTo($issuedAt->copy()->addMinute());
            DB::table('orders')->where('id', 11)->update(['status' => 'CANCELLED']);
            self::assertNull($repo->invitationContext('order_11', $token));
            self::assertFalse($confirm());
            self::assertEquals($assignments, DB::table('gvsu_registration_assignments')->orderBy('attendee_id')->get());
            self::assertEquals($outbox, DB::table('order_effect_outbox')->get());
        } finally {
            $this->travelBack();
        }
    }

    public function test_invitation_delivery_mode_survives_challenge_retention(): void
    {
        config()->set('respondent-confirmation.invitation_enabled', true);
        $token = (new RespondentConfirmationRepository)->issue('order_11', true)->token;
        self::assertTrue(app(RespondentConfirmationService::class)->confirm('order_11', $token, Fixture::payload()));
        self::assertSame(2, DB::table('gvsu_registration_assignments')->where('completion_invitation', true)->count());
        DB::table('respondent_confirmation_challenges')->delete();
        $portal = \Mockery::mock(\HiEvents\Services\Domain\Registration\GvsuRegistrationBridgePortalClient::class);
        $portal->shouldReceive('provision')->once()->withArgs(function ($batch) {
            self::assertTrue($batch['completion_invitation']);
            return true;
        });
        $this->app->instance(\HiEvents\Services\Domain\Registration\GvsuRegistrationBridgePortalClient::class, $portal);
        self::assertTrue(app(\HiEvents\Services\Domain\Registration\GvsuRegistrationBridgeService::class)->provisionCompletedOrder(11));
    }

    public function test_source_refund_attendee_cancellation_and_event_cancellation_win_before_confirmation(): void
    {
        foreach (['refund', 'attendee', 'event'] as $case) {
            Fixture::reset();
            $code = (new RespondentConfirmationRepository)->issue('order_11')->token;
            self::assertNotNull((new RespondentConfirmationRepository)->verify('order_11', $code));
            $results = $this->parallel([
                function ($dir) use ($case) {
                    return DB::transaction(function () use ($dir, $case) {
                        if ($case === 'refund') {
                            DB::table('orders')->where('id', 11)->update(['refund_status' => 'REFUNDED', 'total_refunded' => 36]);
                        } elseif ($case === 'attendee') {
                            DB::table('attendees')->where('id', 112)->update(['status' => 'CANCELLED']);
                        } else {
                            DB::table('events')->where('id', 7)->update(['status' => 'CANCELLED']);
                        }
                        touch($dir.'/locked');
                        for ($i = 0; $i < 500; $i++) {
                            if (DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE datname = current_database() AND application_name = 'respondent_parallel_1' AND wait_event_type = 'Lock'")->n > 0) {
                                return true;
                            }
                            usleep(10000);
                        }
                        throw new \RuntimeException('No database lock wait observed');
                    });
                },
                function ($dir) use ($code) {
                    while (! file_exists($dir.'/locked')) {
                        usleep(1000);
                    }

                    return app(RespondentConfirmationService::class)->confirm('order_11', $code, Fixture::payload());
                },
            ]);
            self::assertSame([true, false], $results);
            self::assertSame(0, DB::table('gvsu_registration_assignments')->count());
            self::assertSame(0, DB::table('order_effect_outbox')->count());
            self::assertNull(DB::table('respondent_confirmation_challenges')->value('consumed_at'));
        }
    }

    public function test_confirmation_wins_lock_then_later_refund_suppresses_provision(): void
    {
        $code = (new RespondentConfirmationRepository)->issue('order_11')->token;
        self::assertNotNull((new RespondentConfirmationRepository)->verify('order_11', $code));
        $results = $this->parallel([
            function ($dir) use ($code) {
                return DB::transaction(function () use ($dir, $code) {
                    $accepted = app(RespondentConfirmationService::class)->confirm('order_11', $code, Fixture::payload());
                    touch($dir.'/confirmed');
                    for ($i = 0; $i < 500; $i++) {
                        if (DB::selectOne("SELECT count(*) AS n FROM pg_stat_activity WHERE datname = current_database() AND application_name = 'respondent_parallel_1' AND wait_event_type = 'Lock'")->n > 0) {
                            return $accepted;
                        }
                        usleep(10000);
                    }
                    throw new \RuntimeException('Refund did not wait for confirmation');
                });
            },
            function ($dir) {
                while (! file_exists($dir.'/confirmed')) {
                    usleep(1000);
                }

                return DB::table('orders')->where('id', 11)->update(['refund_status' => 'REFUNDED', 'total_refunded' => 36]);
            },
        ]);
        self::assertSame([true, 1], $results);
        $portal = \Mockery::mock(\HiEvents\Services\Domain\Registration\GvsuRegistrationBridgePortalClient::class);
        $portal->shouldNotReceive('provision');
        $bridge = new \HiEvents\Services\Domain\Registration\GvsuRegistrationBridgeService($portal);
        self::assertTrue($bridge->provisionCompletedOrder(11));
        self::assertSame(2, DB::table('gvsu_registration_assignments')->where('status', 'bound')->count());
        self::assertSame(1, DB::table('order_effect_outbox')->count());
    }

    public function test_outbox_database_failure_rolls_back_all_bindings_and_consumption(): void
    {
        $code = (new RespondentConfirmationRepository)->issue('order_11')->token;
        self::assertNotNull((new RespondentConfirmationRepository)->verify('order_11', $code));
        DB::unprepared("CREATE OR REPLACE FUNCTION reject_fixture_outbox() RETURNS trigger LANGUAGE plpgsql AS $$ BEGIN RAISE EXCEPTION 'synthetic failure'; END $$; CREATE TRIGGER reject_fixture_outbox BEFORE INSERT ON order_effect_outbox FOR EACH ROW EXECUTE FUNCTION reject_fixture_outbox();");
        try {
            app(RespondentConfirmationService::class)->confirm('order_11', $code, Fixture::payload());
            self::fail('failure expected');
        } catch (QueryException) {
            self::assertSame(0, DB::table('gvsu_registration_assignments')->count());
            self::assertSame(0, DB::table('order_effect_outbox')->count());
            self::assertNull(DB::table('respondent_confirmation_challenges')->value('consumed_at'));
        }
        DB::unprepared('DROP TRIGGER reject_fixture_outbox ON order_effect_outbox; DROP FUNCTION reject_fixture_outbox();');
        self::assertTrue(app(RespondentConfirmationService::class)->confirm('order_11', $code, Fixture::payload()));
    }

    public function test_constraints_reject_duplicate_tokens_orphan_orders_assignments_and_outbox(): void
    {
        $code = (new RespondentConfirmationRepository)->issue('order_11')->token;
        self::assertNotNull((new RespondentConfirmationRepository)->verify('order_11', $code));
        self::assertTrue(app(RespondentConfirmationService::class)->confirm('order_11', $code, Fixture::payload()));
        foreach (['respondent_confirmation_challenges', 'gvsu_registration_assignments', 'order_effect_outbox', 'respondent_confirmation_destinations'] as $table) {
            $row = (array) DB::table($table)->first();
            unset($row['id']);
            try {
                DB::transaction(fn () => DB::table($table)->insert($row));
                self::fail('unique constraint expected');
            } catch (QueryException $e) {
                self::assertSame('23505', $e->errorInfo[0]);
            }
        }
        $row = (array) DB::table('respondent_confirmation_challenges')->first();
        unset($row['id']);
        $row['order_id'] = 999;
        $row['token_digest'] = str_repeat('f', 64);
        try {
            DB::transaction(fn () => DB::table('respondent_confirmation_challenges')->insert($row));
            self::fail('foreign key expected');
        } catch (QueryException $e) {
            self::assertSame('23503', $e->errorInfo[0]);
        }
    }

    public function test_retention_preserves_live_budgets_and_assignments(): void
    {
        $code = (new RespondentConfirmationRepository)->issue('order_11')->token;
        self::assertNotNull((new RespondentConfirmationRepository)->verify('order_11', $code));
        self::assertTrue(app(RespondentConfirmationService::class)->confirm('order_11', $code, Fixture::payload()));
        self::assertSame(['challenges_deleted' => 0, 'destinations_deleted' => 0], app(RespondentConfirmationRetention::class)->purge());
        DB::table('respondent_confirmation_challenges')->update(['expires_at' => now()->subHours(25)]);
        DB::table('respondent_confirmation_destinations')->update(['last_requested_at' => now()->subHours(26), 'window_started_at' => now()->subHours(26)]);
        self::assertSame(['challenges_deleted' => 1, 'destinations_deleted' => 1], app(RespondentConfirmationRetention::class)->purge());
        self::assertSame(2, DB::table('gvsu_registration_assignments')->count());
        self::assertSame(1, DB::table('order_effect_outbox')->count());
        self::assertSame(['challenges_deleted' => 0, 'destinations_deleted' => 0], app(RespondentConfirmationRetention::class)->purge());
    }

    public function test_purge_skips_inflight_issue_and_does_not_reset_shared_budget(): void
    {
        (new RespondentConfirmationRepository)->issue('order_11');
        DB::table('respondent_confirmation_challenges')->delete();
        DB::table('respondent_confirmation_destinations')->update(['last_requested_at' => now()->subHours(26), 'window_started_at' => now()->subHours(26)]);
        $result = $this->parallel([
            function ($dir) {
                return DB::transaction(function () use ($dir) {
                    $issued = (new RespondentConfirmationRepository)->issue('order_11');
                    touch($dir.'/locked');
                    for ($i = 0; $i < 500; $i++) {
                        if (file_exists($dir.'/purged')) {
                            return $issued !== null;
                        }
                        usleep(10000);
                    }
                    throw new \RuntimeException('Purge did not skip locked budget');
                });
            },
            function ($dir) {
                while (! file_exists($dir.'/locked')) {
                    usleep(1000);
                }
                $counts = app(RespondentConfirmationRetention::class)->purge();
                touch($dir.'/purged');

                return $counts;
            },
        ]);
        self::assertSame([true, ['challenges_deleted' => 0, 'destinations_deleted' => 0]], $result);
        self::assertSame(1, DB::table('respondent_confirmation_destinations')->value('request_count'));
        self::assertSame(1, DB::table('respondent_confirmation_challenges')->count());
        self::assertNull((new RespondentConfirmationRepository)->issue('order_12'));
    }

    public function test_retention_expiry_boundary_minimum_floor_and_cli_only_aggregate_audit(): void
    {
        (new RespondentConfirmationRepository)->issue('order_11');
        $this->freezeTime();
        DB::table('respondent_confirmation_challenges')->update(['expires_at' => now()->subHours(24)]);
        self::assertSame(0, app(RespondentConfirmationRetention::class)->purge()['challenges_deleted']);
        DB::table('respondent_confirmation_challenges')->update(['expires_at' => now()->subHours(24)->subSecond()]);
        $this->artisan('respondent-confirmation:purge', ['--batch' => 1])->expectsOutput('{"challenges_deleted":1,"destinations_deleted":0}')->assertSuccessful();
        self::assertSame(1, DB::table('respondent_confirmation_destinations')->count());
        DB::table('respondent_confirmation_destinations')->update(['last_requested_at' => now()->subSeconds(61)]);
        (new RespondentConfirmationRepository)->issue('order_12');
        config()->set('respondent-confirmation.retention_hours', -1);
        DB::table('respondent_confirmation_challenges')->update(['expires_at' => now()->subMinutes(59)]);
        self::assertSame(0, app(RespondentConfirmationRetention::class)->purge()['challenges_deleted']);
        self::assertSame(2, DB::table('respondent_confirmation_destinations')->value('request_count'));
        self::assertFalse(config('respondent-confirmation.purge_schedule_enabled'));
        $this->travelBack();
    }
}
