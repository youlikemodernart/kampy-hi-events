<?php

declare(strict_types=1);

namespace Tests\Feature\Message;

use HiEvents\DomainObjects\Status\EventReminderOccurrenceStatus;
use HiEvents\DomainObjects\Status\OutgoingMessageStatus;
use HiEvents\Models\EventReminderOccurrence;
use HiEvents\Models\OutgoingMessage;
use HiEvents\Repository\Interfaces\EventReminderOccurrenceRepositoryInterface;
use HiEvents\Services\Domain\Message\EventReminderCancellationService;
use HiEvents\Services\Domain\Message\EventReminderDispatchService;
use HiEvents\Services\Domain\Message\EventReminderRecipientClaimService;
use HiEvents\Services\Domain\Message\EventReminderReconciliationService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
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

    public function test_migration_schema_indexes_constraints_nullable_system_actor_and_legacy_message_compatibility(): void
    {
        self::assertTrue(Schema::hasTable('event_reminder_occurrences'));
        self::assertTrue(Schema::hasColumns('event_reminder_occurrences', [
            'message_id', 'payload_digest', 'expected_recipient_count', 'invalid_recipient_count', 'audience_claimed_at',
        ]));
        $indexes = collect(DB::select("SELECT indexname, indexdef FROM pg_indexes WHERE schemaname = 'public'"))->keyBy('indexname');
        self::assertStringContainsString('UNIQUE', $indexes->get('event_reminder_occurrences_identity_unique')->indexdef);
        self::assertStringContainsString('UNIQUE', $indexes->get('outgoing_messages_normalized_recipient_unique')->indexdef);
        self::assertSame('YES', DB::table('information_schema.columns')->where([
            'table_schema' => 'public', 'table_name' => 'messages', 'column_name' => 'sent_by_user_id',
        ])->value('is_nullable'));
        self::assertGreaterThan(0, DB::table('information_schema.table_constraints')->where([
            'table_schema' => 'public', 'table_name' => 'event_reminder_occurrences', 'constraint_type' => 'FOREIGN KEY',
        ])->count());

        [$eventId] = $this->seedScope('legacy');
        $legacyId = DB::table('messages')->insertGetId([
            'event_id' => $eventId, 'subject' => 'Legacy', 'message' => 'Legacy operator row', 'type' => 'ALL_ATTENDEES',
            'status' => 'DRAFT', 'sent_by_user_id' => null, 'created_at' => now(), 'updated_at' => now(),
        ]);
        self::assertSame('OPERATOR', DB::table('messages')->where('id', $legacyId)->value('source'));
        self::assertNull(DB::table('messages')->where('id', $legacyId)->value('source_key'));
    }

    public function test_repository_upsert_and_independent_connection_cas_retain_one_occurrence(): void
    {
        [$eventId, , $occurrence] = $this->seedScope('upsert');
        $identity = ['event_id' => $eventId, 'policy_version' => 'fixture', 'offset_key' => 'fixture-upsert'];
        $updated = app(EventReminderOccurrenceRepositoryInterface::class)->upsertPlanned($identity, [
            'due_at_utc' => now()->subMinute(), 'source_event_start_at_utc' => now()->addDay(),
            'source_event_timezone' => 'UTC', 'content_version' => 'fixture-2', 'theme_projection_version' => 'fixture',
        ]);
        self::assertSame($occurrence->id, $updated->id);

        $first = $this->independentConnection('cas-one');
        $second = $this->independentConnection('cas-two');
        $sql = "UPDATE event_reminder_occurrences SET status = 'CLAIMING', claimed_at = NOW() WHERE id = ? AND status = 'PLANNED'";
        self::assertSame(1, $first->update($sql, [$occurrence->id]));
        self::assertSame(0, $second->update($sql, [$occurrence->id]));
        self::assertSame(1, EventReminderOccurrence::query()->whereKey($occurrence->id)->where('status', EventReminderOccurrenceStatus::CLAIMING->value)->count());
    }

    public function test_normalized_duplicate_claims_after_connection_restart_retain_one_provider_intent_row(): void
    {
        [$eventId, $messageId] = $this->seedScope('recipient');
        $first = $this->recipients->claim($messageId, $eventId, null, ' One@Example.test ', 'Reminder', str_repeat('a', 64));
        DB::purge('pgsql');
        $second = app(EventReminderRecipientClaimService::class)->claim($messageId, $eventId, null, 'one@example.test', 'Reminder', str_repeat('a', 64));
        self::assertNotNull($first);
        self::assertSame($first->id, $second?->id);
        self::assertSame(1, OutgoingMessage::query()->where('message_id', $messageId)->count());
    }

    public function test_partial_claiming_audience_resumes_after_connection_restart_then_sets_complete_marker_and_exact_count(): void
    {
        [$eventId, $messageId, $occurrence] = $this->seedScope('resume');
        $occurrence->update(['status' => EventReminderOccurrenceStatus::CLAIMING->value, 'claimed_at' => now(), 'message_id' => $messageId]);
        $this->recipients->claim($messageId, $eventId, null, 'first@example.test', 'Reminder', str_repeat('b', 64));

        DB::purge('pgsql');
        $resumed = app(EventReminderRecipientClaimService::class);
        $resumed->claim($messageId, $eventId, null, ' FIRST@example.test ', 'Reminder', str_repeat('b', 64));
        $resumed->claim($messageId, $eventId, null, 'second@example.test', 'Reminder', str_repeat('b', 64));
        $occurrence->refresh()->update([
            'status' => EventReminderOccurrenceStatus::DISPATCHING->value,
            'audience_claimed_at' => now(),
            'expected_recipient_count' => OutgoingMessage::query()->where('message_id', $messageId)->count(),
            'invalid_recipient_count' => 0,
        ]);
        self::assertNotNull($occurrence->fresh()->audience_claimed_at);
        self::assertSame(2, $occurrence->fresh()->expected_recipient_count);
        self::assertSame(2, OutgoingMessage::query()->where('message_id', $messageId)->count());
    }

    public function test_invalid_and_normalized_duplicate_addresses_produce_exact_eligibility_count(): void
    {
        [$eventId, $messageId] = $this->seedScope('eligible');
        self::assertNull($this->recipients->claim($messageId, $eventId, null, 'not-an-email', 'Reminder', str_repeat('e', 64)));
        self::assertNotNull($this->recipients->claim($messageId, $eventId, null, ' Valid@Example.test ', 'Reminder', str_repeat('e', 64)));
        self::assertNotNull($this->recipients->claim($messageId, $eventId, null, 'valid@example.test', 'Reminder', str_repeat('e', 64)));
        self::assertSame(1, OutgoingMessage::query()->where('message_id', $messageId)->count());
    }

    public function test_zero_valid_audience_completes_with_exact_expected_count(): void
    {
        [, $messageId, $occurrence] = $this->seedScope('empty');
        $occurrence->update(['status' => EventReminderOccurrenceStatus::DISPATCHING->value, 'audience_claimed_at' => now(), 'expected_recipient_count' => 0, 'invalid_recipient_count' => 2]);
        app(EventReminderDispatchService::class)->aggregate($occurrence->id, $messageId);
        self::assertSame(EventReminderOccurrenceStatus::COMPLETED->value, $occurrence->fresh()->status);
        self::assertSame('no_valid_recipients', $occurrence->fresh()->reason_code);
    }

    public function test_operator_cancellation_accepts_claimed_and_refuses_submitting_sent_and_unknown(): void
    {
        [, $messageId, $occurrence] = $this->seedScope('cancel-ok');
        $this->recipients->claim($messageId, $occurrence->event_id, null, 'cancel@example.test', 'Reminder', str_repeat('c', 64));
        self::assertTrue(app(EventReminderCancellationService::class)->cancelUnsent($occurrence->id)['cancelled']);

        foreach ([OutgoingMessageStatus::SUBMITTING, OutgoingMessageStatus::SENT, OutgoingMessageStatus::UNKNOWN] as $status) {
            [, $unsafeMessageId, $unsafeOccurrence] = $this->seedScope('cancel-'.strtolower($status->name));
            $unsafeOccurrence->update(['status' => EventReminderOccurrenceStatus::DISPATCHING->value]);
            $claim = $this->recipients->claim($unsafeMessageId, $unsafeOccurrence->event_id, null, strtolower($status->name).'@example.test', 'Reminder', str_repeat('c', 64));
            $claim?->update(['status' => $status->name]);
            self::assertSame('provider_handoff_or_unknown', app(EventReminderCancellationService::class)->cancelUnsent($unsafeOccurrence->id)['reason']);
        }
    }

    public function test_stale_submitting_becomes_unknown_and_exact_count_aggregation_preserves_known_no_send_state(): void
    {
        [$eventId, $messageId, $occurrence] = $this->seedScope('stale');
        $claim = $this->recipients->claim($messageId, $eventId, null, 'stale@example.test', 'Reminder', str_repeat('d', 64));
        $claim?->update(['status' => OutgoingMessageStatus::SUBMITTING->name, 'submitted_at' => now()->subHour()]);
        $occurrence->update(['status' => EventReminderOccurrenceStatus::DISPATCHING->value, 'audience_claimed_at' => now(), 'expected_recipient_count' => 1]);
        config()->set('event-reminders.enabled', false);
        app(EventReminderReconciliationService::class)->reconcile();
        self::assertSame(OutgoingMessageStatus::UNKNOWN->name, $claim?->fresh()->status);
        self::assertSame(EventReminderOccurrenceStatus::UNKNOWN->value, $occurrence->fresh()->status);
        self::assertSame(1, OutgoingMessage::query()->where('message_id', $messageId)->count());
    }

    private function independentConnection(string $name): ConnectionInterface
    {
        config()->set("database.connections.$name", config('database.connections.pgsql'));
        DB::purge($name);

        return DB::connection($name);
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
