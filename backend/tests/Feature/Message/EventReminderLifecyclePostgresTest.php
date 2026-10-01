<?php

declare(strict_types=1);

namespace Tests\Feature\Message;

use HiEvents\DomainObjects\Status\EventReminderOccurrenceStatus;
use HiEvents\DomainObjects\Status\OutgoingMessageStatus;
use HiEvents\Models\EventReminderOccurrence;
use HiEvents\Models\OutgoingMessage;
use HiEvents\Services\Domain\Message\EventReminderCancellationService;
use HiEvents\Services\Domain\Message\EventReminderRecipientClaimService;
use HiEvents\Services\Domain\Message\EventReminderReconciliationService;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class EventReminderLifecyclePostgresTest extends TestCase
{
    use DatabaseTransactions;

    private EventReminderRecipientClaimService $recipients;

    protected function setUp(): void
    {
        parent::setUp();
        if (getenv('EVENT_REMINDER_POSTGRES_FIXTURE') !== '1') {
            self::markTestSkipped('Requires the explicitly provisioned disposable PostgreSQL reminder fixture.');
        }
        self::assertSame('pgsql', DB::connection()->getDriverName());
        $this->recipients = app(EventReminderRecipientClaimService::class);
    }

    public function test_migrations_and_normalized_upsert_are_postgres_backed_and_idempotent(): void
    {
        self::assertTrue(DB::getSchemaBuilder()->hasTable('event_reminder_occurrences'));
        [$eventId, $messageId] = $this->seedScope('upsert');
        $first = $this->recipients->claim($messageId, $eventId, null, ' One@Example.test ', 'Reminder', str_repeat('a', 64));
        $second = $this->recipients->claim($messageId, $eventId, null, 'one@example.test', 'Reminder', str_repeat('a', 64));
        self::assertNotNull($first);
        self::assertSame($first->id, $second?->id);
        self::assertSame(1, OutgoingMessage::query()->where('message_id', $messageId)->count());
    }

    public function test_cas_only_claims_once_and_duplicate_address_with_later_inactive_attendee_is_suppressed(): void
    {
        [$eventId, $messageId, $occurrence] = $this->seedScope('cas');
        self::assertSame(1, EventReminderOccurrence::query()->whereKey($occurrence->id)->where('status', EventReminderOccurrenceStatus::PLANNED->value)->update(['status' => EventReminderOccurrenceStatus::CLAIMING->value]));
        self::assertSame(0, EventReminderOccurrence::query()->whereKey($occurrence->id)->where('status', EventReminderOccurrenceStatus::PLANNED->value)->update(['status' => EventReminderOccurrenceStatus::CLAIMING->value]));
        $claim = $this->recipients->claim($messageId, $eventId, null, 'duplicate@example.test', 'Reminder', str_repeat('b', 64));
        self::assertNotNull($claim);
        DB::table('attendees')->where('event_id', $eventId)->update(['status' => 'INACTIVE']);
        self::assertSame(1, OutgoingMessage::query()->where('message_id', $messageId)->count());
    }

    public function test_zero_and_all_invalid_audiences_complete_with_exact_expected_count(): void
    {
        [, $messageId, $occurrence] = $this->seedScope('empty');
        $occurrence->update(['status' => EventReminderOccurrenceStatus::DISPATCHING->value, 'audience_claimed_at' => now(), 'expected_recipient_count' => 0, 'invalid_recipient_count' => 2]);
        app(\HiEvents\Services\Domain\Message\EventReminderDispatchService::class)->aggregate($occurrence->id, $messageId);
        self::assertSame(EventReminderOccurrenceStatus::COMPLETED->value, $occurrence->fresh()->status);
        self::assertSame('no_valid_recipients', $occurrence->fresh()->reason_code);
    }

    public function test_operator_cancellation_is_allowed_before_and_refused_after_provider_handoff(): void
    {
        [, $messageId, $occurrence] = $this->seedScope('cancel');
        $claim = $this->recipients->claim($messageId, $occurrence->event_id, null, 'cancel@example.test', 'Reminder', str_repeat('c', 64));
        $result = app(EventReminderCancellationService::class)->cancelUnsent($occurrence->id);
        self::assertTrue($result['cancelled']);
        $occurrence->update(['status' => EventReminderOccurrenceStatus::DISPATCHING->value]);
        $claim?->update(['status' => OutgoingMessageStatus::SUBMITTING->name]);
        self::assertSame('provider_handoff_or_unknown', app(EventReminderCancellationService::class)->cancelUnsent($occurrence->id)['reason']);
    }

    public function test_stale_submitting_becomes_unknown_and_aggregate_waits_for_exact_count_without_duplicate_intent(): void
    {
        [$eventId, $messageId, $occurrence] = $this->seedScope('stale');
        $claim = $this->recipients->claim($messageId, $eventId, null, 'stale@example.test', 'Reminder', str_repeat('d', 64));
        $claim?->update(['status' => OutgoingMessageStatus::SUBMITTING->name, 'submitted_at' => now()->subHour()]);
        $occurrence->update(['status' => EventReminderOccurrenceStatus::DISPATCHING->value, 'audience_claimed_at' => now(), 'expected_recipient_count' => 2]);
        config()->set('event-reminders.enabled', false);
        app(EventReminderReconciliationService::class)->reconcile();
        self::assertSame(OutgoingMessageStatus::UNKNOWN->name, $claim?->fresh()->status);
        app(\HiEvents\Services\Domain\Message\EventReminderDispatchService::class)->aggregate($occurrence->id, $messageId);
        self::assertSame(EventReminderOccurrenceStatus::DISPATCHING->value, $occurrence->fresh()->status);
        self::assertSame(1, OutgoingMessage::query()->where('message_id', $messageId)->count());
    }

    private function seedScope(string $suffix): array
    {
        $now = now();
        $accountId = DB::table('accounts')->insertGetId(['name' => "Reminder $suffix", 'email' => "$suffix-account@example.test", 'short_id' => "rem-$suffix", 'currency_code' => 'USD', 'timezone' => 'UTC', 'created_at' => $now, 'updated_at' => $now]);
        $userId = DB::table('users')->insertGetId(['email' => "$suffix-user@example.test", 'password' => 'fixture', 'first_name' => 'Fixture', 'timezone' => 'UTC', 'created_at' => $now, 'updated_at' => $now]);
        $organizerId = DB::table('organizers')->insertGetId(['account_id' => $accountId, 'name' => 'Fixture', 'email' => "$suffix-organizer@example.test", 'currency' => 'USD', 'timezone' => 'UTC', 'created_at' => $now, 'updated_at' => $now]);
        $eventId = DB::table('events')->insertGetId(['account_id' => $accountId, 'organizer_id' => $organizerId, 'user_id' => $userId, 'title' => 'Fixture', 'short_id' => "event-$suffix", 'status' => 'LIVE', 'currency' => 'USD', 'timezone' => 'UTC', 'start_date' => now()->addDay(), 'end_date' => now()->addDays(2), 'created_at' => $now, 'updated_at' => $now]);
        $messageId = DB::table('messages')->insertGetId(['event_id' => $eventId, 'subject' => 'Reminder', 'message' => 'Fixture', 'type' => 'ALL_ATTENDEES', 'status' => 'PROCESSING', 'source' => 'EVENT_REMINDER', 'source_key' => "fixture:$suffix", 'created_at' => $now, 'updated_at' => $now]);
        $id = DB::table('event_reminder_occurrences')->insertGetId(['event_id' => $eventId, 'policy_version' => 'fixture', 'offset_key' => "fixture-$suffix", 'due_at_utc' => $now, 'source_event_start_at_utc' => now()->addDay(), 'source_event_timezone' => 'UTC', 'content_version' => 'fixture', 'theme_projection_version' => 'fixture', 'status' => EventReminderOccurrenceStatus::PLANNED->value, 'message_id' => $messageId, 'created_at' => $now, 'updated_at' => $now]);

        return [$eventId, $messageId, EventReminderOccurrence::query()->findOrFail($id)];
    }
}
