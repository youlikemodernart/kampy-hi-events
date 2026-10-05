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
