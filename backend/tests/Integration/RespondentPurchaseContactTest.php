<?php

namespace Tests\Integration;

use HiEvents\Mail\RespondentConfirmationChallenge;
use HiEvents\Repository\Eloquent\OrderPurchaseContactRepository;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Mail;
use Tests\Support\RespondentCheckoutFixture as Fixture;
use Tests\TestCase;

class RespondentPurchaseContactTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('KAMP_RESPONDENT_DISPOSABLE') !== '1') {
            $this->markTestSkipped('Disposable runner required');
        }
        Fixture::migrate();
        Fixture::seed();
        Event::fake([\HiEvents\Events\OrderStatusChangedEvent::class]);
        $this->app->instance(\HiEvents\Services\Domain\Mail\SendOrderDetailsService::class, app(\HiEvents\Services\Domain\Mail\SendOrderDetailsService::class));
        Mail::fake();
    }

    private function checkout(int $id = 11): void
    {
        foreach ($this->app['router']->getRoutes() as $route) {
            $route->flushController();
        }
        $this->putJson('/public/events/7/order/order_'.$id.'?session_identifier=synthetic-session-'.$id, Fixture::contactPayload())->assertOk();
        self::assertSame('buyer@example.test', app(OrderPurchaseContactRepository::class)->email($id));
        Fixture::paid($id);
    }

    private function edit(string $shortId, string $email): string
    {
        $response = $this->patchJson('/public/events/7/order/'.$shortId, ['email' => $email])->assertOk();

        return $response->json('new_short_id');
    }

    private function requestCode(string $shortId): void
    {
        $this->withHeaders(['Origin' => 'https://tickets.kamplove.org', 'X-Kamp-Respondent-Intent' => 'confirm'])->postJson('/public/registration/orders/'.$shortId.'/request-verification')->assertStatus(202)->assertDontSee('buyer@example.test');
    }

    private function confirm(string $shortId, string $code, int $status): void
    {
        $this->withHeaders(['Origin' => 'https://tickets.kamplove.org', 'X-Kamp-Respondent-Intent' => 'confirm'])->postJson('/public/registration/orders/'.$shortId.'/confirm-respondents', ['verification_code' => $code, 'acknowledged' => true, 'respondents' => Fixture::respondents(11)])->assertStatus($status);
    }

    public function test_receipt_email_takeover_cannot_redirect_purchase_challenge_or_read_anchor(): void
    {
        $this->checkout();
        $short = $this->edit('order_11', 'attacker@example.test');
        $this->getJson('/public/events/7/order/'.$short.'?include=event')->assertOk()->assertDontSee('buyer@example.test')->assertDontSee('email_encrypted')->assertDontSee('purchase_contact');
        $this->requestCode($short);
        Mail::assertSent(RespondentConfirmationChallenge::class, 1);
        Mail::assertSent(RespondentConfirmationChallenge::class, fn ($mail) => $mail->hasTo('buyer@example.test') && ! $mail->hasTo('attacker@example.test'));
        $this->confirm($short, str_repeat('0', 64), 409);
        self::assertSame(0, DB::table('gvsu_registration_assignments')->count());
        self::assertSame(0, DB::table('order_effect_outbox')->count());
        $this->confirm($short, Mail::sent(RespondentConfirmationChallenge::class)->first()->confirmationCode, 200);
        self::assertSame(2, DB::table('gvsu_registration_assignments')->count());
        self::assertSame(1, DB::table('order_effect_outbox')->count());
    }

    public function test_change_between_issue_and_consume_keeps_original_mailbox_authority(): void
    {
        $this->checkout();
        $this->requestCode('order_11');
        $code = Mail::sent(RespondentConfirmationChallenge::class)->first()->confirmationCode;
        $short = $this->edit('order_11', 'attacker@example.test');
        $this->confirm($short, str_repeat('0', 64), 409);
        $this->confirm($short, $code, 200);
        self::assertSame('buyer@example.test', app(OrderPurchaseContactRepository::class)->email(11));
    }

    public function test_pre_activation_email_edits_and_missing_provenance_fail_closed(): void
    {
        $this->checkout();
        DB::table('order_purchase_contacts')->delete(); // Simulated pre-migration order: never backfilled.
        config()->set('respondent-confirmation.enabled', false);
        $short = $this->edit('order_11', 'attacker@example.test');
        config()->set('respondent-confirmation.enabled', true);
        $this->requestCode($short);
        Mail::assertNotSent(RespondentConfirmationChallenge::class);
        $this->confirm($short, str_repeat('0', 64), 409);
        self::assertSame(0, DB::table('order_purchase_contacts')->count());
        self::assertSame(0, DB::table('gvsu_registration_assignments')->count());
        self::assertSame(0, DB::table('order_effect_outbox')->count());
    }

    public function test_checkout_requires_actual_session_and_retry_cannot_replace_anchor(): void
    {
        $this->putJson('/public/events/7/order/order_11', Fixture::contactPayload())->assertStatus(403);
        self::assertSame(0, DB::table('order_purchase_contacts')->count());
        $this->checkout();
        $changed = Fixture::contactPayload();
        $changed['order']['email_confirmation'] = $changed['order']['email'] = 'attacker@example.test';
        $this->putJson('/public/events/7/order/order_11?session_identifier=synthetic-session-11', $changed)->assertStatus(409);
        self::assertSame(1, DB::table('order_purchase_contacts')->count());
        self::assertSame('buyer@example.test', app(OrderPurchaseContactRepository::class)->email(11));
        self::assertStringNotContainsString('buyer@example.test', DB::table('order_purchase_contacts')->value('email_encrypted'));
    }

    public function test_restricted_intake_role_can_confirm_but_cannot_change_anchor_or_read_unrelated_answers(): void
    {
        $this->checkout();
        DB::unprepared("DO $$ BEGIN IF NOT EXISTS (SELECT FROM pg_roles WHERE rolname='respondent_intake_test') THEN CREATE ROLE respondent_intake_test NOLOGIN; END IF; END $$;
            GRANT USAGE ON SCHEMA public TO respondent_intake_test;
            GRANT SELECT, UPDATE ON orders, events, attendees TO respondent_intake_test;
            GRANT SELECT, INSERT ON order_purchase_contacts TO respondent_intake_test;
            GRANT SELECT, INSERT, UPDATE ON respondent_confirmation_destinations, respondent_confirmation_challenges, gvsu_registration_assignments, order_effect_outbox TO respondent_intake_test;
            GRANT USAGE ON SEQUENCE order_purchase_contacts_id_seq, respondent_confirmation_destinations_id_seq, respondent_confirmation_challenges_id_seq, gvsu_registration_assignments_id_seq, order_effect_outbox_id_seq TO respondent_intake_test;");
        DB::statement('SET ROLE respondent_intake_test');
        try {
            app(OrderPurchaseContactRepository::class)->capture(12, 7, 'second@example.test');
            self::assertSame('second@example.test', app(OrderPurchaseContactRepository::class)->email(12));
            foreach ([11 => '23505', 999 => '23503'] as $id => $expected) {
                try {
                    app(OrderPurchaseContactRepository::class)->capture($id, 7, 'replacement@example.test');
                    self::fail('Invalid anchor insert succeeded');
                } catch (\Illuminate\Database\QueryException $e) {
                    self::assertSame($expected, $e->errorInfo[0]);
                }
            }
            $repo = app(\HiEvents\Repository\Eloquent\RespondentConfirmationRepository::class);
            $issued = $repo->issue('order_11');
            self::assertSame('buyer@example.test', $issued->email);
            self::assertTrue(app(\HiEvents\Services\Domain\Registration\RespondentConfirmationService::class)->confirm('order_11', $issued->token, Fixture::respondents(11)));
            foreach (['UPDATE order_purchase_contacts SET email_encrypted = email_encrypted', 'DELETE FROM order_purchase_contacts', 'SELECT * FROM question_answers', 'ALTER TABLE order_purchase_contacts ADD COLUMN takeover text'] as $sql) {
                try {
                    DB::statement($sql);
                    self::fail('Restricted operation succeeded');
                } catch (\Illuminate\Database\QueryException $e) {
                    self::assertSame('42501', $e->errorInfo[0]);
                }
            }
        } finally {
            DB::statement('RESET ROLE');
        }
        try {
            DB::table('order_purchase_contacts')->update(['email_encrypted' => 'replacement']);
            self::fail('Owner update bypassed immutable trigger');
        } catch (\Illuminate\Database\QueryException $e) {
            self::assertSame('P0001', $e->errorInfo[0]);
        }
        self::assertSame('buyer@example.test', app(OrderPurchaseContactRepository::class)->email(11));
    }

    public function test_concurrent_checkout_serializes_first_contact_and_loser_cannot_overwrite(): void
    {
        $dir = sys_get_temp_dir().'/purchase-contact-'.bin2hex(random_bytes(8));
        mkdir($dir, 0700);
        DB::disconnect('pgsql');
        $pids = [];
        try {
            foreach ([0, 1] as $i) {
                $pid = pcntl_fork();
                if ($pid === -1) {
                    throw new \RuntimeException('fork failed');
                }
                if ($pid === 0) {
                    DB::purge('pgsql');
                    while (! file_exists($dir.'/start')) {
                        usleep(1000);
                    }
                    $payload = Fixture::contactPayload();
                    $payload['order']['email'] = $payload['order']['email_confirmation'] = 'buyer'.$i.'@example.test';
                    $response = $this->putJson('/public/events/7/order/order_11?session_identifier=synthetic-session-11', $payload);
                    file_put_contents($dir.'/'.$i, (string) $response->status());
                    exit(0);
                }
                $pids[] = $pid;
            }
            touch($dir.'/start');
            foreach ($pids as $pid) {
                pcntl_waitpid($pid, $status);
                self::assertSame(0, pcntl_wexitstatus($status));
            }
            DB::purge('pgsql');
            $statuses = [file_get_contents($dir.'/0'), file_get_contents($dir.'/1')];
            $winner = array_search('200', $statuses, true);
            sort($statuses);
            self::assertSame(['200', '409'], $statuses);
            self::assertSame('buyer'.$winner.'@example.test', app(OrderPurchaseContactRepository::class)->email(11));
            self::assertSame(1, DB::table('order_purchase_contacts')->count());
            self::assertSame(2, DB::table('attendees')->count());
        } finally {
            DB::purge('pgsql');
            foreach (glob($dir.'/*') as $file) {
                unlink($file);
            }
            rmdir($dir);
        }
    }

    public function test_checkout_failure_rolls_back_anchor_and_payment_is_required_before_challenge(): void
    {
        $payload = Fixture::contactPayload();
        array_pop($payload['products']);
        $this->putJson('/public/events/7/order/order_11?session_identifier=synthetic-session-11', $payload)->assertStatus(409);
        self::assertSame(0, DB::table('order_purchase_contacts')->count());
        self::assertNull(DB::table('orders')->where('id', 11)->value('email'));
        $this->putJson('/public/events/7/order/order_11?session_identifier=synthetic-session-11', Fixture::contactPayload())->assertOk();
        self::assertSame(1, DB::table('order_purchase_contacts')->count());
        $this->requestCode('order_11');
        Mail::assertNotSent(RespondentConfirmationChallenge::class);
        self::assertSame(0, DB::table('respondent_confirmation_challenges')->count());
    }
}
