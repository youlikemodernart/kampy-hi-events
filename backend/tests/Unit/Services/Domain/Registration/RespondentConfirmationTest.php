<?php

namespace Tests\Unit\Services\Domain\Registration;

use HiEvents\Http\Actions\Registration\RespondentConfirmationRequestGate;
use HiEvents\Mail\RespondentConfirmationChallenge;
use HiEvents\Repository\Eloquent\RespondentConfirmationRepository;
use HiEvents\Services\Domain\Order\OrderEffectOutboxService;
use HiEvents\Services\Domain\Registration\GvsuRegistrationBridgePortalClient;
use HiEvents\Services\Domain\Registration\GvsuRegistrationBridgeService;
use HiEvents\Services\Domain\Registration\RespondentConfirmationService;
use HiEvents\Services\Infrastructure\HtmlPurifier\HtmlPurifierService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class RespondentConfirmationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config()->set('jwt.secret', str_repeat('j', 32));
        config()->set('respondent-confirmation.capture_enabled', true);
        config()->set('respondent-confirmation.enabled', true);
        config()->set('database.default', 'sqlite');
        config()->set('database.connections.sqlite.database', ':memory:');
        DB::purge('sqlite');
        config()->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        config()->set('services.gvsu_registration_bridge.mode', 'live');
        config()->set('services.gvsu_registration_bridge.email_hmac_current_key', str_repeat('h', 43));
        Schema::create('events', function (Blueprint $t) {
            $t->id();
            $t->string('status');
            $t->timestamp('end_date');
            $t->timestamp('start_date')->nullable();
            $t->string('timezone')->default('America/Detroit');
            $t->softDeletes();
        });
        Schema::create('orders', function (Blueprint $t) {
            $t->id();
            $t->integer('event_id');
            $t->string('short_id');
            $t->string('status');
            $t->string('payment_status');
            $t->string('refund_status')->nullable();
            $t->decimal('total_refunded')->default(0);
            $t->string('email');
            $t->softDeletes();
        });
        Schema::create('attendees', function (Blueprint $t) {
            $t->id();
            $t->integer('event_id');
            $t->integer('order_id');
            $t->string('status');
            $t->string('public_id');
            $t->string('first_name');
            $t->string('last_name');
            $t->softDeletes();
        });
        (require database_path('migrations/2026_09_22_000001_create_gvsu_registration_assignments_table.php'))->up();
        (require database_path('migrations/2026_10_05_000001_create_respondent_confirmation_challenges.php'))->up();
        (require database_path('migrations/2026_10_05_000002_create_order_purchase_contacts.php'))->up();
        // PostgreSQL migration constraints are exercised by the dedicated disposable suite.
        Schema::create('order_receipt_recovery_evidence', fn (Blueprint $t) => $t->integer('order_id'));
        Schema::table('gvsu_registration_assignments', function (Blueprint $t) {
            $t->integer('recovery_evidence_id')->nullable();
            $t->boolean('completion_invitation')->default(false);
        });
        Schema::table('respondent_confirmation_challenges', function (Blueprint $t) {
            $t->boolean('invitation')->default(false);
            $t->string('authority_type')->nullable();
            $t->integer('authority_id')->nullable();
            $t->string('authority_commitment')->nullable();
            $t->timestamp('verified_at')->nullable();
            $t->string('verified_context_digest')->nullable();
        });
        DB::table('events')->insert(['id' => 7, 'status' => 'LIVE', 'end_date' => '2099-10-18 16:00:00']);
        foreach ([11, 12] as $id) {
            DB::table('orders')->insert(['id' => $id, 'event_id' => 7, 'short_id' => 'order_'.$id, 'status' => 'COMPLETED', 'payment_status' => 'PAYMENT_RECEIVED', 'email' => 'buyer@example.test']);
        }
        foreach ([11, 12] as $id) {
            (new \HiEvents\Repository\Eloquent\OrderPurchaseContactRepository)->capture($id, 7, 'buyer@example.test');
        }
        foreach ([21, 22] as $id) {
            DB::table('attendees')->insert(['id' => $id, 'event_id' => 7, 'order_id' => 11, 'status' => 'ACTIVE', 'public_id' => 'ticket_'.$id, 'first_name' => 'Invented', 'last_name' => 'Attendee '.$id]);
        }
        Mail::fake();
    }

    public function test_disabled_invitation_keeps_private_headers_without_cookies_queries_or_mail(): void
    {
        $action = app(\HiEvents\Http\Actions\Registration\CompletionInvitationAction::class);
        DB::enableQueryLog();
        DB::flushQueryLog();
        foreach ([[false, false], [false, true], [true, false]] as [$intake, $invitation]) {
            config()->set('respondent-confirmation.enabled', $intake);
            config()->set('respondent-confirmation.invitation_enabled', $invitation);
            foreach (['GET', 'POST'] as $method) {
                $request = Request::create('/registration/invitation/order_11', $method, [], [], [], [
                    'HTTP_ORIGIN' => 'https://tickets.kamplove.org',
                    'HTTP_X_KAMP_RESPONDENT_INTENT' => 'confirm',
                    'CONTENT_TYPE' => 'application/json',
                ], json_encode(['action' => 'open', 'token' => 'invalid']));
                $response = $action($request, 'order_11');
                self::assertSame(404, $response->getStatusCode());
                self::assertTrue($response->headers->hasCacheControlDirective('no-store'));
                self::assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
                self::assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
                self::assertSame("default-src 'none'; script-src 'self'; style-src 'self' 'unsafe-inline'; font-src 'self'; connect-src 'self'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'", $response->headers->get('Content-Security-Policy'));
                self::assertCount(0, $response->headers->getCookies());
            }
        }
        self::assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
        Mail::assertNothingSent();
    }

    public function test_invitation_is_read_only_frozen_single_use_and_resumable(): void
    {
        config()->set('respondent-confirmation.invitation_enabled', true);
        $repo = new RespondentConfirmationRepository;
        $issued = $repo->issue('order_11', true);
        $before = DB::table('respondent_confirmation_challenges')->first();
        self::assertNull($repo->invitationContext('order_12', $issued->token));
        self::assertNull($repo->invitationContext('order_11', str_repeat('0', 64)));
        for ($i = 0; $i < 3; $i++) {
            self::assertFalse($repo->invitationContext('order_11', $issued->token)['confirmed']);
        }
        self::assertEquals($before, DB::table('respondent_confirmation_challenges')->first());
        for ($i = 0; $i < 8; $i++) {
            self::assertFalse($repo->confirm('order_11', str_repeat('0', 64), $this->payload(), fn () => self::fail('invalid capability')));
        }
        self::assertSame(0, DB::table('respondent_confirmation_challenges')->value('attempts'));
        $calls = 0;
        self::assertTrue($repo->confirm('order_11', $issued->token, $this->payload(), function () use (&$calls) {
            $calls++;
        }));
        self::assertNull($repo->invitationContext('order_11', $issued->token)); // Stub binding created no assignments; cannot hand off phantom work.
        self::assertTrue($repo->confirm('order_11', $issued->token, $this->payload(), function () use (&$calls) {
            $calls++;
        }));
        self::assertSame(1, $calls);
        $changed = $this->payload();
        $changed[0]['email'] = 'changed@example.test';
        self::assertFalse($repo->confirm('order_11', $issued->token, $changed, fn () => self::fail('rebound')));
        DB::table('attendees')->where('id', 21)->update(['first_name' => 'Changed']);
        self::assertNull($repo->invitationContext('order_11', $issued->token));
    }

    public function test_invitation_cookie_expiry_requires_email_reopen_without_expiring_invitation(): void
    {
        config()->set('respondent-confirmation.invitation_enabled', true);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-06T02:00:00Z'));
        try {
            $repo = new RespondentConfirmationRepository;
            $token = $repo->issue('order_11', true)->token;
            $before = DB::table('respondent_confirmation_challenges')->first();
            $action = app(\HiEvents\Http\Actions\Registration\CompletionInvitationAction::class);
            $path = '/registration/invitation/order_11';
            $get = $action(Request::create($path, 'GET'), 'order_11');
            self::assertSame(200, $get->getStatusCode());
            self::assertCount(0, $get->headers->getCookies());
            $post = function (array $body, array $cookies = []) use ($action, $path) {
                return $action(Request::create($path, 'POST', [], $cookies, [], ['HTTP_ORIGIN' => 'https://tickets.kamplove.org', 'HTTP_X_KAMP_RESPONDENT_INTENT' => 'confirm', 'CONTENT_TYPE' => 'application/json'], json_encode($body)), 'order_11');
            };
            $this->travel(8)->hours();
            $opened = $post(['action' => 'open', 'token' => $token]);
            self::assertSame(200, $opened->getStatusCode());
            $cookie = $opened->headers->getCookies()[0];
            self::assertTrue($cookie->isSecure());
            self::assertTrue($cookie->isHttpOnly());
            self::assertSame('strict', $cookie->getSameSite());
            self::assertSame('/api/registration/invitation/order_11', $cookie->getPath());
            self::assertSame(200, $post(['action' => 'resume'], [$cookie->getName() => $cookie->getValue()])->getStatusCode());
            $this->travel(15)->minutes();
            self::assertSame(409, $post(['action' => 'resume'], [$cookie->getName() => $cookie->getValue()])->getStatusCode());
            self::assertSame(200, $post(['action' => 'open', 'token' => $token])->getStatusCode());
            self::assertEquals($before, DB::table('respondent_confirmation_challenges')->first());
            self::assertSame(0, DB::table('gvsu_registration_assignments')->count());
        } finally {
            $this->travelBack();
        }
    }

    public function test_invitation_respects_earlier_event_end_and_legacy_code_still_expires_in_fifteen_minutes(): void
    {
        config()->set('respondent-confirmation.invitation_enabled', true);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-06T02:00:00Z'));
        try {
            DB::table('events')->where('id', 7)->update(['end_date' => '2026-10-07 02:00:00']);
            $repo = new RespondentConfirmationRepository;
            $token = $repo->issue('order_11', true)->token;
            self::assertSame('2026-10-07 02:00:00', DB::table('respondent_confirmation_challenges')->value('expires_at'));
            $this->travel(61)->seconds();
            DB::table('attendees')->insert(['id' => 23, 'order_id' => 12, 'event_id' => 7, 'status' => 'ACTIVE', 'public_id' => 'ticket_23', 'first_name' => 'Invented', 'last_name' => 'Legacy']);
            $legacy = $repo->issue('order_12')->token;
            $this->travel(15)->minutes();
            self::assertNull($repo->verify('order_12', $legacy));
            self::assertNotNull($repo->invitationContext('order_11', $token));
            $this->travelTo(\Carbon\Carbon::parse('2026-10-07T02:00:00Z'));
            self::assertNull($repo->invitationContext('order_11', $token));
        } finally {
            $this->travelBack();
        }
    }

    public function test_invitation_email_uses_fragment_and_no_separate_code(): void
    {
        config()->set('respondent-confirmation.invitation_enabled', true);
        app(RespondentConfirmationService::class)->request('order_11');
        Mail::assertSent(RespondentConfirmationChallenge::class, function ($mail) {
            self::assertTrue($mail->hasTo('buyer@example.test'));
            self::assertStringContainsString('/api/registration/invitation/order_11#', $mail->completionUrl);
            self::assertStringNotContainsString('Verification code', $mail->render());

            return true;
        });
    }

    public function test_prior_capability_is_blocked_by_canary_for_invitation_and_reverse_state_without_disclosure(): void
    {
        config()->set('respondent-confirmation.invitation_enabled', true);
        DB::table('events')->where('id', 7)->update(['start_date' => '2099-10-17 20:00:00']);
        $repo = new RespondentConfirmationRepository;
        $issued = $repo->issue('order_11', true);
        $outbox = Mockery::mock(OrderEffectOutboxService::class);
        $outbox->shouldReceive('enqueueRespondentConfirmation')->once()->with(11);
        $this->app->instance(OrderEffectOutboxService::class, $outbox);
        self::assertTrue(app(RespondentConfirmationService::class)->confirm('order_11', $issued->token, $this->payload()));
        $assignment = DB::table('gvsu_registration_assignments')->orderBy('attendee_id')->first();
        $candidate = ['operation' => 'gvsu-registration-current-state-v1', 'event_id' => '7', 'order_id' => '11', 'attendee_id' => '21',
            'assignment_id' => $assignment->assignment_id, 'respondent_id' => $assignment->respondent_id, 'public_ticket_id' => 'ticket_21',
            'attendee_display_name' => $assignment->attendee_display_name, 'respondent_identity_digest_sha256' => $assignment->respondent_identity_digest_sha256,
            'designated_delivery_email' => 'adult@example.test'];
        config()->set('services.gvsu_registration_bridge.incoming_current_digest', hash('sha256', str_repeat('b', 43)));
        foreach ([['live', [], true], ['canary', [11], true], ['canary', [12], false], ['canary', [], false], ['live', [12], true]] as [$mode, $ids, $allowed]) {
            config()->set('services.gvsu_registration_bridge.mode', $mode);
            config()->set('services.gvsu_registration_bridge.canary_order_ids', $ids);
            self::assertSame($allowed, $repo->invitationContext('order_11', $issued->token) !== null);
            $state = app(GvsuRegistrationBridgeService::class)->currentState($candidate);
            self::assertSame($allowed ? 'current' : 'blocked', $state['status']);
            $response = $this->withHeader('Authorization', 'Bearer '.str_repeat('b', 43))->postJson('/internal/gvsu-registration/current-state', $candidate)->assertOk();
            self::assertSame($state['status'], $response->json('status'));
            self::assertStringNotContainsString('adult@example.test', $response->getContent());
            self::assertStringNotContainsString('Invented', $response->getContent());
            if (! $allowed) {
                self::assertArrayNotHasKey('order_id', $state);
                self::assertArrayNotHasKey('assignment_id', $state);
            }
        }
    }

    public function test_canary_reserves_one_attempt_before_mail_and_never_replaces_prior_link_even_after_unknown_send(): void
    {
        config()->set('respondent-confirmation.invitation_enabled', true);
        config()->set('services.gvsu_registration_bridge.mode', 'canary');
        config()->set('services.gvsu_registration_bridge.canary_order_ids', [11]);
        $this->travelTo(\Carbon\Carbon::parse('2026-10-06T02:00:00Z'));
        try {
            $calls = 0;
            Mail::shouldReceive('to')->once()->andReturnSelf();
            Mail::shouldReceive('send')->once()->andReturnUsing(function () use (&$calls) {
                $calls++;
                self::assertSame(1, DB::table('respondent_confirmation_challenges')->count());
                throw new \RuntimeException('synthetic unknown send');
            });
            try {
                app(RespondentConfirmationService::class)->request('order_11');
                self::fail('expected unknown transport result');
            } catch (\RuntimeException $error) {
                self::assertSame('synthetic unknown send', $error->getMessage());
            }
            $before = DB::table('respondent_confirmation_challenges')->first();
            $this->travel(2)->hours();
            app(RespondentConfirmationService::class)->request('order_11');
            self::assertNull((new RespondentConfirmationRepository)->issue('order_11', false));
            self::assertEquals($before, DB::table('respondent_confirmation_challenges')->first());
            self::assertSame(1, $calls);
            DB::table('respondent_confirmation_challenges')->update(['expires_at' => now()->subDays(2)]);
            $retention = new \HiEvents\Services\Domain\Registration\RespondentConfirmationRetention;
            self::assertSame(0, $retention->purge()['challenges_deleted']);
            $this->travelTo(\Carbon\Carbon::parse('2026-10-18T20:00:01Z'));
            self::assertSame(1, $retention->purge()['challenges_deleted']);
            self::assertNull((new RespondentConfirmationRepository)->issue('order_11', true));
        } finally {
            $this->travelBack();
        }
    }

    public function test_canary_public_request_denies_neighbor_and_prior_legacy_attempt_after_cooldown(): void
    {
        $repo = new RespondentConfirmationRepository;
        $prior = $repo->issue('order_11');
        config()->set('respondent-confirmation.invitation_enabled', true);
        config()->set('services.gvsu_registration_bridge.mode', 'canary');
        config()->set('services.gvsu_registration_bridge.canary_order_ids', [11]);
        $this->travel(61)->minutes();
        try {
            $headers = ['Origin' => 'https://tickets.kamplove.org', 'X-Kamp-Respondent-Intent' => 'confirm'];
            $this->withHeaders($headers)->postJson('/public/registration/orders/order_11/request-verification')->assertStatus(202);
            config()->set('services.gvsu_registration_bridge.canary_order_ids', [12]);
            $this->withHeaders($headers)->postJson('/public/registration/orders/order_11/request-verification')->assertStatus(202);
            self::assertSame(hash('sha256', $prior->token), DB::table('respondent_confirmation_challenges')->value('token_digest'));
            self::assertSame(1, DB::table('respondent_confirmation_challenges')->count());
            Mail::assertNothingSent();
        } finally {
            $this->travelBack();
        }
    }

    private function payload(): array
    {
        return [
            ['attendee_id' => 21, 'route' => 'adult', 'respondent_name' => '', 'email' => 'adult@example.test'],
            ['attendee_id' => 22, 'route' => 'guardian', 'respondent_name' => 'Invented Guardian', 'email' => 'guardian@example.test'],
        ];
    }

    public function test_issue_has_authoritative_destination_hash_only_storage_cooldown_and_order_isolation(): void
    {
        $repo = new RespondentConfirmationRepository;
        self::assertNull($repo->issue('missing'));
        $issued = $repo->issue('order_11');
        self::assertNotNull($repo->verify('order_11', $issued->token));
        self::assertSame('buyer@example.test', $issued->email);
        self::assertSame(64, strlen($issued->token));
        $row = DB::table('respondent_confirmation_challenges')->first();
        self::assertSame(hash('sha256', $issued->token), $row->token_digest);
        self::assertStringNotContainsString($issued->token, json_encode($row));
        self::assertStringNotContainsString('buyer@example.test', json_encode($row));
        self::assertNull($repo->issue('order_11'));
        self::assertNull($repo->issue('order_12'));
        self::assertFalse($repo->confirm('order_12', $issued->token, $this->payload(), fn () => self::fail('cross-order bind')));
        Mail::assertNothingSent();
    }

    public function test_create_only_all_siblings_atomic_consumption_replay_and_bridge_only_outbox(): void
    {
        $repo = new RespondentConfirmationRepository;
        $issued = $repo->issue('order_11');
        self::assertNotNull($repo->verify('order_11', $issued->token));
        $portal = Mockery::mock(GvsuRegistrationBridgePortalClient::class);
        $portal->shouldNotReceive('provision');
        $outbox = Mockery::mock(OrderEffectOutboxService::class);
        $outbox->shouldReceive('enqueueRespondentConfirmation')->once()->with(11);
        $service = new RespondentConfirmationService($repo, new GvsuRegistrationBridgeService($portal), $outbox, app(HtmlPurifierService::class));
        self::assertFalse($service->confirm('order_11', $issued->token, [$this->payload()[0]]));
        self::assertSame(0, DB::table('gvsu_registration_assignments')->count());
        self::assertTrue($service->confirm('order_11', $issued->token, $this->payload()));
        self::assertTrue($service->confirm('order_11', $issued->token, $this->payload()));
        self::assertSame(2, DB::table('gvsu_registration_assignments')->count());
        self::assertNotNull(DB::table('respondent_confirmation_challenges')->first()->consumed_at);
        $changed = $this->payload();
        $changed[1]['email'] = 'different@example.test';
        self::assertFalse($service->confirm('order_11', $issued->token, $changed));
        self::assertNull($repo->issue('order_11'));
        Mail::assertNothingSent();
    }

    public function test_invalid_attempts_expiry_refunds_and_cancellation_never_bind(): void
    {
        $repo = new RespondentConfirmationRepository;
        $issued = $repo->issue('order_11');
        self::assertNotNull($repo->verify('order_11', $issued->token));
        $noBind = fn () => self::fail('unauthorized bind');
        for ($i = 0; $i < 5; $i++) {
            self::assertFalse($repo->confirm('order_11', str_repeat('0', 64), $this->payload(), $noBind));
        }
        self::assertSame(5, DB::table('respondent_confirmation_challenges')->first()->attempts);
        self::assertFalse($repo->confirm('order_11', $issued->token, $this->payload(), $noBind));
        DB::table('respondent_confirmation_challenges')->update(['attempts' => 0, 'expires_at' => now()->subSecond()]);
        self::assertFalse($repo->confirm('order_11', $issued->token, $this->payload(), $noBind));
        DB::table('respondent_confirmation_challenges')->update(['expires_at' => now()->addMinutes(15)]);
        DB::table('orders')->where('id', 11)->update(['refund_status' => 'REFUNDED', 'total_refunded' => 36]);
        self::assertFalse($repo->confirm('order_11', $issued->token, $this->payload(), $noBind));
        DB::table('orders')->where('id', 11)->update(['refund_status' => null, 'total_refunded' => 0, 'status' => 'CANCELLED']);
        self::assertFalse($repo->confirm('order_11', $issued->token, $this->payload(), $noBind));
    }

    public function test_hourly_budget_resend_invalidates_prior_code_and_atomic_rollback(): void
    {
        $repo = new RespondentConfirmationRepository;
        $first = $repo->issue('order_11');
        $this->travel(61)->seconds();
        $second = $repo->issue('order_11');
        self::assertFalse($repo->confirm('order_11', $first->token, $this->payload(), fn () => self::fail('old code')));
        for ($i = 0; $i < 3; $i++) {
            $this->travel(61)->seconds();
            self::assertNotNull($repo->issue('order_11'));
        }
        $this->travel(61)->seconds();
        self::assertNull($repo->issue('order_11'));
        self::assertSame(5, DB::table('respondent_confirmation_challenges')->count());
        $this->travel(61)->minutes();
        $fresh = $repo->issue('order_11');
        self::assertNotNull($repo->verify('order_11', $fresh->token));
        try {
            $repo->confirm('order_11', $fresh->token, $this->payload(), function () {
                DB::table('attendees')->where('id', 21)->update(['first_name' => 'Changed']);
                throw new \HiEvents\Exceptions\ResourceConflictException('fixture rollback');
            });
            self::fail('expected rollback');
        } catch (\HiEvents\Exceptions\ResourceConflictException) {
            self::assertSame('Invented', DB::table('attendees')->where('id', 21)->value('first_name'));
            self::assertNull(DB::table('respondent_confirmation_challenges')->orderByDesc('id')->first()->consumed_at);
        }
        $this->travelBack();
    }

    public function test_http_request_is_generic_user_triggered_and_never_returns_buyer_address(): void
    {
        config()->set('respondent-confirmation.invitation_enabled', true);
        config()->set('respondent-confirmation.enabled', true);
        $headers = ['Origin' => 'https://tickets.kamplove.org', 'X-Kamp-Respondent-Intent' => 'confirm'];
        $existing = $this->withHeaders($headers)->postJson('/public/registration/orders/order_11/request-verification')->assertStatus(202);
        $missing = $this->withHeaders($headers)->postJson('/public/registration/orders/missing/request-verification')->assertStatus(202);
        self::assertSame($existing->getContent(), $missing->getContent());
        self::assertStringNotContainsString('buyer@example.test', $existing->getContent());
        Mail::assertSent(RespondentConfirmationChallenge::class, 1);
        Mail::assertSent(RespondentConfirmationChallenge::class, fn ($mail) => $mail->hasTo('buyer@example.test'));
        $this->withHeaders($headers)->postJson('/public/registration/orders/order_11/request-verification')->assertStatus(202);
        Mail::assertSent(RespondentConfirmationChallenge::class, 1);
        self::assertSame(0, DB::table('gvsu_registration_assignments')->count());
        $this->get('/public/registration/orders/order_11/confirm-respondents')->assertStatus(405);
        $outbox = Mockery::mock(OrderEffectOutboxService::class);
        $outbox->shouldReceive('enqueueRespondentConfirmation')->once()->with(11);
        $this->app->instance(OrderEffectOutboxService::class, $outbox);
        $code = Mail::sent(RespondentConfirmationChallenge::class)->first()->confirmationCode;
        $this->withHeaders($headers)->postJson('/public/registration/orders/order_11/confirm-respondents', ['verification_code' => $code, 'acknowledged' => true, 'respondents' => $this->payload()])->assertOk()->assertJson(['status' => 'confirmed']);
        Mail::assertSent(RespondentConfirmationChallenge::class, 1);
    }

    public function test_origin_method_content_type_and_explicit_intent_gate(): void
    {
        config()->set('respondent-confirmation.enabled', false);
        $request = Request::create('/public/registration/orders/order_11/confirm-respondents', 'POST', [], [], [], ['HTTP_ORIGIN' => 'https://tickets.kamplove.org', 'HTTP_X_KAMP_RESPONDENT_INTENT' => 'confirm', 'CONTENT_TYPE' => 'application/json'], '{}');
        self::assertFalse(RespondentConfirmationRequestGate::allows($request));
        config()->set('respondent-confirmation.enabled', true);
        self::assertTrue(RespondentConfirmationRequestGate::allows($request));
        foreach (['https://evil.example', 'null', 'https://tickets.kamplove.org.evil.example'] as $origin) {
            $request->headers->set('Origin', $origin);
            self::assertFalse(RespondentConfirmationRequestGate::allows($request));
        }
        $request->headers->set('Origin', 'https://tickets.kamplove.org');
        $request->setMethod('GET');
        self::assertFalse(RespondentConfirmationRequestGate::allows($request));
        $request->setMethod('POST');
        $request->headers->remove('X-Kamp-Respondent-Intent');
        self::assertFalse(RespondentConfirmationRequestGate::allows($request));
    }
}
