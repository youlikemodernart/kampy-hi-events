<?php

declare(strict_types=1);

namespace Tests\Feature\Message;

use Carbon\CarbonImmutable;
use HiEvents\DomainObjects\Enums\MessagingTierViolationEnum;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\EventReminderOccurrenceStatus;
use HiEvents\DomainObjects\Status\OutgoingMessageStatus;
use HiEvents\Jobs\Message\SendEventReminderRecipientJob;
use HiEvents\Models\EventReminderOccurrence;
use HiEvents\Models\OutgoingMessage;
use HiEvents\Repository\Interfaces\EventReminderOccurrenceRepositoryInterface;
use HiEvents\Services\Domain\Email\DTO\UniversityEmailThemeDTO;
use HiEvents\Services\Domain\Message\DTO\EventReminderContext;
use HiEvents\Services\Domain\Message\DTO\MessagingTierViolationDTO;
use HiEvents\Services\Domain\Message\EventReminderCancellationService;
use HiEvents\Services\Domain\Message\EventReminderContextBuilder;
use HiEvents\Services\Domain\Message\EventReminderDispatchService;
use HiEvents\Services\Domain\Message\EventReminderRecipientClaimService;
use HiEvents\Services\Domain\Message\EventReminderReconciliationService;
use HiEvents\Services\Domain\Message\MessagingEligibilityService;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class EventReminderLifecyclePostgresTest extends TestCase
{
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
        $indexes = collect(DB::select(<<<'SQL'
SELECT index_class.relname AS index_name, index_row.indisunique, index_row.indisvalid,
       index_row.indpred IS NULL AS no_predicate, index_row.indexprs IS NULL AS no_expressions,
       index_row.indnatts, index_row.indnkeyatts,
       array_to_json((SELECT array_agg(attribute.attname::text ORDER BY key.position)
                      FROM unnest(index_row.indkey::smallint[]) WITH ORDINALITY key(attnum, position)
                      JOIN pg_attribute attribute ON attribute.attrelid = index_row.indrelid AND attribute.attnum = key.attnum))::text AS columns
FROM pg_index index_row
JOIN pg_class index_class ON index_class.oid = index_row.indexrelid
JOIN pg_class table_class ON table_class.oid = index_row.indrelid
JOIN pg_namespace table_namespace ON table_namespace.oid = table_class.relnamespace
WHERE table_namespace.nspname = 'public' AND index_class.relname IN (
    'event_reminder_occurrences_identity_unique', 'outgoing_messages_normalized_recipient_unique'
)
SQL))->keyBy('index_name');
        foreach ([
            'event_reminder_occurrences_identity_unique' => ['event_id', 'policy_version', 'offset_key'],
            'outgoing_messages_normalized_recipient_unique' => ['message_id', 'recipient_normalized'],
        ] as $name => $expectedColumns) {
            $index = $indexes->get($name);
            self::assertNotNull($index);
            self::assertTrue(filter_var($index->indisunique, FILTER_VALIDATE_BOOL));
            self::assertTrue(filter_var($index->indisvalid, FILTER_VALIDATE_BOOL));
            self::assertTrue(filter_var($index->no_predicate, FILTER_VALIDATE_BOOL));
            self::assertTrue(filter_var($index->no_expressions, FILTER_VALIDATE_BOOL));
            self::assertSame(count($expectedColumns), (int) $index->indnatts);
            self::assertSame(count($expectedColumns), (int) $index->indnkeyatts);
            self::assertSame($expectedColumns, json_decode($index->columns, true, flags: JSON_THROW_ON_ERROR));
        }
        $columns = collect(DB::select(<<<'SQL'
SELECT table_name, column_name, data_type, character_maximum_length, is_nullable, column_default
FROM information_schema.columns
WHERE table_schema = 'public' AND (table_name, column_name) IN (
    ('messages', 'sent_by_user_id'),
    ('event_reminder_occurrences', 'payload_digest'),
    ('outgoing_messages', 'attempt_count')
)
SQL))->keyBy(static fn (object $column): string => "$column->table_name.$column->column_name");
        self::assertSame(['bigint', null, 'YES'], [
            $columns->get('messages.sent_by_user_id')->data_type,
            $columns->get('messages.sent_by_user_id')->character_maximum_length,
            $columns->get('messages.sent_by_user_id')->is_nullable,
        ]);
        self::assertSame(['character varying', 64, 'YES'], [
            $columns->get('event_reminder_occurrences.payload_digest')->data_type,
            (int) $columns->get('event_reminder_occurrences.payload_digest')->character_maximum_length,
            $columns->get('event_reminder_occurrences.payload_digest')->is_nullable,
        ]);
        self::assertSame(['integer', 'NO', '0'], [
            $columns->get('outgoing_messages.attempt_count')->data_type,
            $columns->get('outgoing_messages.attempt_count')->is_nullable,
            $columns->get('outgoing_messages.attempt_count')->column_default,
        ]);
        $foreignKeys = collect(DB::select(<<<'SQL'
SELECT source_namespace.nspname AS source_namespace, source.relname AS source_table,
       source_column.attname AS source_column, target_namespace.nspname AS target_namespace,
       target.relname AS target_table, target_column.attname AS target_column,
       constraint_row.confdeltype AS delete_action, cardinality(constraint_row.conkey) AS source_key_count,
       cardinality(constraint_row.confkey) AS target_key_count
FROM pg_constraint constraint_row
JOIN pg_class source ON source.oid = constraint_row.conrelid
JOIN pg_namespace source_namespace ON source_namespace.oid = source.relnamespace
JOIN pg_class target ON target.oid = constraint_row.confrelid
JOIN pg_namespace target_namespace ON target_namespace.oid = target.relnamespace
JOIN pg_attribute source_column ON source_column.attrelid = source.oid AND source_column.attnum = constraint_row.conkey[1]
JOIN pg_attribute target_column ON target_column.attrelid = target.oid AND target_column.attnum = constraint_row.confkey[1]
WHERE constraint_row.contype = 'f' AND source_namespace.nspname = 'public'
  AND source.relname = 'event_reminder_occurrences' AND source_column.attname IN ('event_id', 'message_id')
SQL))->keyBy('source_column');
        self::assertSame(['public', 'event_reminder_occurrences', 'events', 'id', 'public', 'c', 1, 1], [
            $foreignKeys->get('event_id')->source_namespace, $foreignKeys->get('event_id')->source_table,
            $foreignKeys->get('event_id')->target_table, $foreignKeys->get('event_id')->target_column,
            $foreignKeys->get('event_id')->target_namespace, $foreignKeys->get('event_id')->delete_action,
            (int) $foreignKeys->get('event_id')->source_key_count, (int) $foreignKeys->get('event_id')->target_key_count,
        ]);
        self::assertSame(['public', 'event_reminder_occurrences', 'messages', 'id', 'public', 'n', 1, 1], [
            $foreignKeys->get('message_id')->source_namespace, $foreignKeys->get('message_id')->source_table,
            $foreignKeys->get('message_id')->target_table, $foreignKeys->get('message_id')->target_column,
            $foreignKeys->get('message_id')->target_namespace, $foreignKeys->get('message_id')->delete_action,
            (int) $foreignKeys->get('message_id')->source_key_count, (int) $foreignKeys->get('message_id')->target_key_count,
        ]);
        $migrations = DB::table('migrations')->selectRaw('migration, count(*) AS row_count')->whereIn('migration', [
            '2026_10_01_000000_create_event_reminder_occurrences_table',
            '2026_10_01_000001_add_reminder_identity_to_messages_and_outgoing_messages',
        ])->groupBy('migration')->pluck('row_count', 'migration')->map(static fn (mixed $count): int => (int) $count)->all();
        self::assertSame([
            '2026_10_01_000000_create_event_reminder_occurrences_table' => 1,
            '2026_10_01_000001_add_reminder_identity_to_messages_and_outgoing_messages' => 1,
        ], $migrations);

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

    public function test_forked_independent_connections_race_the_real_occurrence_cas_and_produce_one_winner(): void
    {
        [, , $occurrence] = $this->seedScope('fork-occurrence');
        $barrier = sys_get_temp_dir().'/event-reminder-occurrence-fork-'.bin2hex(random_bytes(8));
        mkdir($barrier);
        $children = [];
        for ($worker = 0; $worker < 2; $worker++) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                DB::purge('pgsql');
                file_put_contents("$barrier/ready-$worker", '1');
                $deadline = microtime(true) + 5;
                while (! file_exists("$barrier/release") && microtime(true) < $deadline) {
                    usleep(1_000);
                }
                $won = app(EventReminderOccurrenceRepositoryInterface::class)->claimDue($occurrence->id, now());
                file_put_contents("$barrier/result-$worker", $won ? 'winner' : 'loser');
                exit(0);
            }
            $children[] = $pid;
        }
        $deadline = microtime(true) + 5;
        while (count(glob("$barrier/ready-*")) !== 2 && microtime(true) < $deadline) {
            usleep(1_000);
        }
        self::assertCount(2, glob("$barrier/ready-*"));
        touch("$barrier/release");
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        self::assertSame(['loser', 'winner'], collect(glob("$barrier/result-*"))->map(fn (string $path): string => file_get_contents($path))->sort()->values()->all());
        self::assertSame(EventReminderOccurrenceStatus::CLAIMING->value, $occurrence->fresh()->status);
        array_map('unlink', glob("$barrier/*"));
        rmdir($barrier);
    }

    public function test_forked_independent_connections_race_the_real_recipient_claim_and_retain_one_durable_row(): void
    {
        [$eventId, $messageId] = $this->seedScope('fork-cas');
        $barrier = sys_get_temp_dir().'/event-reminder-fork-'.bin2hex(random_bytes(8));
        mkdir($barrier);
        $children = [];
        for ($worker = 0; $worker < 2; $worker++) {
            $pid = pcntl_fork();
            self::assertNotSame(-1, $pid);
            if ($pid === 0) {
                DB::purge('pgsql');
                file_put_contents("$barrier/ready-$worker", '1');
                $deadline = microtime(true) + 5;
                while (! file_exists("$barrier/release") && microtime(true) < $deadline) {
                    usleep(1_000);
                }
                $claim = app(EventReminderRecipientClaimService::class)->claim($messageId, $eventId, null, 'Race@Example.test', 'Reminder', str_repeat('f', 64));
                exit($claim === null ? 1 : 0);
            }
            $children[] = $pid;
        }
        $deadline = microtime(true) + 5;
        while (count(glob("$barrier/ready-*")) !== 2 && microtime(true) < $deadline) {
            usleep(1_000);
        }
        self::assertCount(2, glob("$barrier/ready-*"));
        touch("$barrier/release");
        foreach ($children as $pid) {
            pcntl_waitpid($pid, $status);
            self::assertSame(0, pcntl_wexitstatus($status));
        }
        self::assertSame(1, OutgoingMessage::query()->where('message_id', $messageId)->where('recipient_normalized', 'race@example.test')->count());
        array_map('unlink', glob("$barrier/*"));
        rmdir($barrier);
    }

    public function test_dispatch_claimed_resumes_real_partial_audience_with_real_attendees_invalid_count_and_queue_jobs(): void
    {
        [$eventId, , $occurrence] = $this->seedScope('dispatch-resume');
        $this->configureDispatch($eventId, $occurrence, 'fixture-dispatch-resume');
        $message = $this->claimingMessage($occurrence);
        $this->seedAttendeeGraph($eventId, 'first@example.test', 'first');
        $this->seedAttendeeGraph($eventId, ' SECOND@example.test ', 'second');
        $this->seedAttendeeGraph($eventId, 'invalid-email', 'invalid');
        $this->recipients->claim($message->id, $eventId, null, 'first@example.test', 'Reminder', $this->fixtureDigest());
        $this->bindPermittedDispatch();
        Queue::fake();

        app(EventReminderDispatchService::class)->dispatchClaimed($occurrence->id);

        $fresh = $occurrence->fresh();
        self::assertSame(EventReminderOccurrenceStatus::DISPATCHING->value, $fresh->status);
        self::assertNotNull($fresh->audience_claimed_at);
        self::assertSame(2, $fresh->expected_recipient_count);
        self::assertSame(1, $fresh->invalid_recipient_count);
        self::assertSame(['first@example.test', 'second@example.test'], OutgoingMessage::query()->where('message_id', $message->id)->orderBy('recipient_normalized')->pluck('recipient_normalized')->all());
        Queue::assertPushed(SendEventReminderRecipientJob::class, 2);
    }

    public function test_active_duplicate_email_allows_one_real_handoff_after_provenance_attendee_is_deactivated_and_replay_is_blocked(): void
    {
        [$eventId, $messageId, $occurrence] = $this->seedScope('recipient-handoff');
        $this->configureDispatch($eventId, $occurrence, 'fixture-recipient-handoff');
        $first = $this->seedAttendeeGraph($eventId, 'Duplicate@Example.test', 'provenance');
        $this->seedAttendeeGraph($eventId, 'duplicate@example.test', 'still-active');
        $claim = $this->recipients->claim($messageId, $eventId, $first, 'Duplicate@Example.test', 'Reminder', $this->fixtureDigest());
        $occurrence->update(['status' => EventReminderOccurrenceStatus::DISPATCHING->value, 'audience_claimed_at' => now(), 'expected_recipient_count' => 1]);
        DB::table('attendees')->where('id', $first)->update(['status' => AttendeeStatus::CANCELLED->name]);
        $this->bindPermittedDispatch();
        $sentMessage = Mockery::mock(\Illuminate\Mail\SentMessage::class);
        $sentMessage->shouldReceive('getMessageId')->once()->andReturn('provider-message-id');
        $pending = Mockery::mock();
        $pending->shouldReceive('sendNow')->once()->andReturn($sentMessage);
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('getSymfonyTransport')->once()->andReturn(Mockery::mock(\Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport::class));
        $mailer->shouldReceive('to')->once()->with('Duplicate@Example.test')->andReturn($pending);
        app()->instance(Mailer::class, $mailer);

        app(EventReminderDispatchService::class)->sendRecipient($claim->id);
        app(EventReminderDispatchService::class)->sendRecipient($claim->id);

        self::assertSame(OutgoingMessageStatus::SENT->name, $claim->fresh()->status);
        self::assertSame('provider-message-id', $claim->fresh()->provider_message_id);
        self::assertNotNull($claim->fresh()->provider_accepted_at);
        self::assertSame(1, OutgoingMessage::query()->where('message_id', $messageId)->count());
    }

    public function test_missing_transport_message_id_is_unknown_and_never_claimed_as_provider_accepted(): void
    {
        [$eventId, $messageId, $occurrence] = $this->seedScope('missing-id');
        $this->configureDispatch($eventId, $occurrence, 'fixture-missing-id');
        $attendeeId = $this->seedAttendeeGraph($eventId, 'missing-provider-id@example.test', 'missing-id');
        $claim = $this->recipients->claim($messageId, $eventId, $attendeeId, 'missing-provider-id@example.test', 'Reminder', $this->fixtureDigest());
        $occurrence->update(['status' => EventReminderOccurrenceStatus::DISPATCHING->value, 'audience_claimed_at' => now(), 'expected_recipient_count' => 1]);
        $this->bindPermittedDispatch();
        $pending = Mockery::mock();
        $pending->shouldReceive('sendNow')->once()->andReturnNull();
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('getSymfonyTransport')->once()->andReturn(Mockery::mock(\Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport::class));
        $mailer->shouldReceive('to')->once()->with('missing-provider-id@example.test')->andReturn($pending);
        app()->instance(Mailer::class, $mailer);

        app(EventReminderDispatchService::class)->sendRecipient($claim->id);

        self::assertSame(OutgoingMessageStatus::UNKNOWN->name, $claim->fresh()->status);
        self::assertSame('provider_message_id_missing', $claim->fresh()->last_error_class);
        self::assertNull($claim->fresh()->provider_message_id);
        self::assertNull($claim->fresh()->provider_accepted_at);
        self::assertSame(EventReminderOccurrenceStatus::UNKNOWN->value, $occurrence->fresh()->status);
    }

    public function test_non_postmark_transport_is_suppressed_before_handoff_and_generated_id_cannot_claim_acceptance(): void
    {
        [$eventId, $messageId, $occurrence] = $this->seedScope('wrong-mailer');
        $this->configureDispatch($eventId, $occurrence, 'fixture-wrong-mailer');
        $attendeeId = $this->seedAttendeeGraph($eventId, 'wrong-mailer@example.test', 'wrong-mailer');
        $claim = $this->recipients->claim($messageId, $eventId, $attendeeId, 'wrong-mailer@example.test', 'Reminder', $this->fixtureDigest());
        $occurrence->update(['status' => EventReminderOccurrenceStatus::DISPATCHING->value, 'audience_claimed_at' => now(), 'expected_recipient_count' => 1]);
        $this->bindPermittedDispatch();
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('getSymfonyTransport')->once()->andReturn(new \Symfony\Component\Mailer\Transport\NullTransport);
        $mailer->shouldNotReceive('to');
        app()->instance(Mailer::class, $mailer);

        app(EventReminderDispatchService::class)->sendRecipient($claim->id);

        self::assertSame(OutgoingMessageStatus::SUPPRESSED->name, $claim->fresh()->status);
        self::assertSame('postmark_api_transport_required', $claim->fresh()->last_error_class);
        self::assertNull($claim->fresh()->provider_message_id);
        self::assertNull($claim->fresh()->provider_accepted_at);
        self::assertSame(0, $claim->fresh()->attempt_count);
        self::assertSame(EventReminderOccurrenceStatus::COMPLETED->value, $occurrence->fresh()->status);
    }

    /** @dataProvider preHandoffSuppressionCases */
    public function test_real_submitting_cas_then_final_recheck_suppresses_without_provider_intent(string $mutation): void
    {
        [$eventId, $messageId, $occurrence] = $this->seedScope('pre-handoff-'.$mutation);
        $this->configureDispatch($eventId, $occurrence, 'fixture-pre-handoff-'.$mutation);
        $attendeeId = $this->seedAttendeeGraph($eventId, "$mutation@example.test", $mutation);
        $claim = $this->recipients->claim($messageId, $eventId, $attendeeId, "$mutation@example.test", 'Reminder', $this->fixtureDigest());
        $occurrence->update(['status' => EventReminderOccurrenceStatus::DISPATCHING->value, 'audience_claimed_at' => now(), 'expected_recipient_count' => 1]);
        $this->bindPermittedDispatch();
        $realRecipients = app(EventReminderRecipientClaimService::class);
        $raceRecipients = Mockery::mock(EventReminderRecipientClaimService::class)->makePartial();
        $raceRecipients->shouldReceive('markSubmitting')->once()->andReturnUsing(function (OutgoingMessage $row) use ($realRecipients, $mutation, $occurrence): bool {
            $claimed = $realRecipients->markSubmitting($row);
            if ($mutation === 'kill-switch') {
                config()->set('event-reminders.enabled', false);
            } elseif ($mutation === 'archived') {
                DB::table('events')->where('id', $occurrence->event_id)->update(['status' => 'DRAFT']);
            } else {
                $occurrence->update(['due_at_utc' => now()->subMinute()]);
            }

            return $claimed;
        });
        app()->instance(EventReminderRecipientClaimService::class, $raceRecipients);
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldReceive('getSymfonyTransport')->once()->andReturn(Mockery::mock(\Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport::class));
        $mailer->shouldNotReceive('to');
        app()->instance(Mailer::class, $mailer);

        app(EventReminderDispatchService::class)->sendRecipient($claim->id);

        self::assertSame(OutgoingMessageStatus::SUPPRESSED->name, $claim->fresh()->status);
        self::assertSame('binding_changed_before_handoff', $claim->fresh()->last_error_class);
    }

    public static function preHandoffSuppressionCases(): array
    {
        return [['kill-switch'], ['archived'], ['due-drift']];
    }

    public function test_real_tier_violation_dto_stops_provider_intent_at_production_recheck(): void
    {
        [$eventId, $messageId, $occurrence] = $this->seedScope('tier-recheck');
        $this->configureDispatch($eventId, $occurrence, 'fixture-tier-recheck');
        $attendeeId = $this->seedAttendeeGraph($eventId, 'tier@example.test', 'tier');
        $claim = $this->recipients->claim($messageId, $eventId, $attendeeId, 'tier@example.test', 'Reminder', $this->fixtureDigest());
        $occurrence->update(['status' => EventReminderOccurrenceStatus::DISPATCHING->value, 'audience_claimed_at' => now(), 'expected_recipient_count' => 1]);
        $this->bindPermittedDispatch(new MessagingTierViolationDTO($occurrence->event_id, 'Fixture', [MessagingTierViolationEnum::RECIPIENT_LIMIT_EXCEEDED]));
        $mailer = Mockery::mock(Mailer::class);
        $mailer->shouldNotReceive('to');
        app()->instance(Mailer::class, $mailer);

        app(EventReminderDispatchService::class)->sendRecipient($claim->id);

        self::assertSame(OutgoingMessageStatus::SUPPRESSED->name, $claim->fresh()->status);
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('lateGraceReconciliationCases')]
    public function test_reconciliation_applies_the_reviewed_six_hour_boundary(int $lateMinutes, string $expectedStatus): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC'));

        try {
            [$eventId, , $occurrence] = $this->seedScope('late-boundary-'.$lateMinutes);
            $now = CarbonImmutable::now('UTC');
            $dueAt = $now->subMinutes($lateMinutes);
            $eventStart = $dueAt->addMinutes(10080);
            DB::table('events')->where('id', $eventId)->update(['start_date' => $eventStart, 'timezone' => 'America/Detroit']);
            $occurrence->update([
                'policy_version' => 'kamp-attendee-reminders-v1',
                'offset_key' => '7-days',
                'due_at_utc' => $dueAt,
                'source_event_start_at_utc' => $eventStart,
                'source_event_timezone' => 'America/Detroit',
                'status' => EventReminderOccurrenceStatus::PLANNED->value,
            ]);
            config()->set('event-reminders', array_merge(config('event-reminders'), [
                'enabled' => true,
                'event_allowlist' => [$eventId],
                'policy_version' => 'kamp-attendee-reminders-v1',
                'offsets' => ['7-days' => -10080, '24-hours' => -1440],
                'late_grace_minutes' => 360,
            ]));

            app(EventReminderReconciliationService::class)->reconcile();

            self::assertSame($expectedStatus, $occurrence->fresh()->status);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public static function lateGraceReconciliationCases(): array
    {
        return [
            'five-hours-fifty-nine-minutes' => [359, EventReminderOccurrenceStatus::PLANNED->value],
            'exactly-six-hours' => [360, EventReminderOccurrenceStatus::PLANNED->value],
            'six-hours-one-minute' => [361, EventReminderOccurrenceStatus::SKIPPED_LATE->value],
        ];
    }

    public function test_reconciliation_skips_an_occurrence_after_the_event_has_started_even_inside_grace(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC'));

        try {
            [$eventId, , $occurrence] = $this->seedScope('post-start');
            $eventStart = CarbonImmutable::now('UTC')->subMinute();
            DB::table('events')->where('id', $eventId)->update(['start_date' => $eventStart]);
            $occurrence->update([
                'policy_version' => 'fixture-post-start',
                'offset_key' => 'event-start',
                'due_at_utc' => $eventStart,
                'source_event_start_at_utc' => $eventStart,
                'status' => EventReminderOccurrenceStatus::PLANNED->value,
            ]);
            config()->set('event-reminders', array_merge(config('event-reminders'), [
                'enabled' => true,
                'event_allowlist' => [$eventId],
                'policy_version' => 'fixture-post-start',
                'offsets' => ['event-start' => 0],
                'late_grace_minutes' => 360,
            ]));

            app(EventReminderReconciliationService::class)->reconcile();

            self::assertSame(EventReminderOccurrenceStatus::SKIPPED_LATE->value, $occurrence->fresh()->status);
            self::assertSame('outside_late_grace_or_event_started', $occurrence->fresh()->reason_code);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    public function test_recipient_claimed_inside_grace_is_suppressed_when_the_job_starts_outside_grace(): void
    {
        CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-10-04 12:00:00', 'UTC'));

        try {
            [$eventId, $messageId, $occurrence] = $this->seedScope('queued-past-grace');
            $dueAt = CarbonImmutable::now('UTC')->subMinutes(359);
            $eventStart = $dueAt->addMinutes(10080);
            DB::table('events')->where('id', $eventId)->update(['start_date' => $eventStart, 'status' => 'LIVE']);
            $attendeeId = $this->seedAttendeeGraph($eventId, 'late-queue@example.test', 'late-queue');
            $claim = $this->recipients->claim($messageId, $eventId, $attendeeId, 'late-queue@example.test', 'Reminder', $this->fixtureDigest());
            $occurrence->update([
                'policy_version' => 'kamp-attendee-reminders-v1',
                'offset_key' => '7-days',
                'due_at_utc' => $dueAt,
                'source_event_start_at_utc' => $eventStart,
                'status' => EventReminderOccurrenceStatus::DISPATCHING->value,
                'audience_claimed_at' => now(),
                'expected_recipient_count' => 1,
            ]);
            config()->set('event-reminders', array_merge(config('event-reminders'), [
                'enabled' => true,
                'event_allowlist' => [$eventId],
                'offsets' => ['7-days' => -10080, '24-hours' => -1440],
                'late_grace_minutes' => 360,
            ]));
            $this->bindPermittedDispatch();
            $mailer = Mockery::mock(Mailer::class);
            $mailer->shouldNotReceive('to');
            app()->instance(Mailer::class, $mailer);
            CarbonImmutable::setTestNow($dueAt->addMinutes(361));

            app(EventReminderDispatchService::class)->sendRecipient($claim->id);

            self::assertSame(OutgoingMessageStatus::SUPPRESSED->name, $claim->fresh()->status);
            self::assertSame('late_grace_elapsed_before_handoff', $claim->fresh()->last_error_class);
            self::assertNull($claim->fresh()->provider_accepted_at);
            self::assertSame(0, $claim->fresh()->attempt_count);
            self::assertSame(EventReminderOccurrenceStatus::COMPLETED->value, $occurrence->fresh()->status);
        } finally {
            CarbonImmutable::setTestNow();
        }
    }

    private function bindPermittedDispatch(?MessagingTierViolationDTO $tierViolation = null): void
    {
        $context = $this->fixtureContext();
        $contexts = Mockery::mock(EventReminderContextBuilder::class);
        $contexts->shouldReceive('build')->andReturn($context);
        app()->instance(EventReminderContextBuilder::class, $contexts);
        $eligibility = Mockery::mock(MessagingEligibilityService::class);
        $eligibility->shouldReceive('checkEligibility')->andReturn(null);
        $eligibility->shouldReceive('checkTierLimits')->andReturn($tierViolation);
        app()->instance(MessagingEligibilityService::class, $eligibility);
    }

    private function fixtureContext(): EventReminderContext
    {
        return new EventReminderContext('Fixture', 'https://fixture.test/event', 'January 1, 2030 1:00 PM', 'UTC', null, 'support@fixture.test', 'sender@fixture.test', 'reply@fixture.test', '1 Fixture Way', 'https://fixture.test/preferences', 'Fixture', new UniversityEmailThemeDTO('#111111', '#222222', '#ffffff', '#ffffff', '#eeeeee'));
    }

    private function fixtureDigest(): string
    {
        $context = $this->fixtureContext();
        $html = (new \HiEvents\Mail\Event\EventReminder($context))->render();
        $text = view('emails.event.reminder-text', ['context' => $context->payload(), 'theme' => $context->theme])->render();

        return hash('sha256', $html."\n".$text);
    }

    private function configureDispatch(int $eventId, EventReminderOccurrence $occurrence, string $offsetKey): void
    {
        $start = now()->addHours(2)->startOfSecond();
        $due = $start->copy()->subMinutes(60);
        DB::table('events')->where('id', $eventId)->update(['start_date' => $start, 'status' => 'LIVE']);
        $occurrence->update(['offset_key' => $offsetKey, 'due_at_utc' => $due, 'source_event_start_at_utc' => $start]);
        config()->set('event-reminders', array_merge(config('event-reminders'), ['enabled' => true, 'event_allowlist' => [$eventId], 'offsets' => [$offsetKey => -60], 'reply_to' => 'reply@fixture.test', 'physical_address' => '1 Fixture Way', 'preference_url' => 'https://fixture.test/preferences']));
    }

    private function claimingMessage(EventReminderOccurrence $occurrence): \HiEvents\Models\Message
    {
        $message = \HiEvents\Models\Message::query()->create(['event_id' => $occurrence->event_id, 'subject' => 'Reminder', 'message' => 'Fixture', 'type' => 'ALL_ATTENDEES', 'status' => 'PROCESSING', 'source' => 'EVENT_REMINDER', 'source_key' => 'event-reminder:'.$occurrence->id, 'sent_by_user_id' => null, 'send_data' => ['payload_digest' => $this->fixtureDigest(), 'text' => 'Fixture']]);
        $occurrence->update(['status' => EventReminderOccurrenceStatus::CLAIMING->value, 'claimed_at' => now(), 'message_id' => $message->id]);

        return $message;
    }

    private function seedAttendeeGraph(int $eventId, string $email, string $suffix): int
    {
        $now = now();
        $productId = DB::table('products')->insertGetId(['event_id' => $eventId, 'title' => 'Fixture '.$suffix, 'order' => 1, 'created_at' => $now, 'updated_at' => $now]);
        $priceId = DB::table('product_prices')->insertGetId(['product_id' => $productId, 'price' => 10, 'created_at' => $now, 'updated_at' => $now]);
        $orderId = DB::table('orders')->insertGetId(['short_id' => 'ord-'.$suffix, 'event_id' => $eventId, 'total_before_additions' => 10, 'total_refunded' => 0, 'total_gross' => 10, 'currency' => 'USD', 'first_name' => 'Fixture', 'last_name' => 'Recipient', 'email' => $email, 'status' => 'COMPLETED', 'public_id' => 'public-'.$suffix, 'created_at' => $now, 'updated_at' => $now]);

        return DB::table('attendees')->insertGetId(['short_id' => 'att-'.$suffix, 'first_name' => 'Fixture', 'last_name' => 'Recipient', 'email' => $email, 'order_id' => $orderId, 'product_id' => $productId, 'event_id' => $eventId, 'public_id' => 'attendee-'.$suffix, 'status' => AttendeeStatus::ACTIVE->name, 'product_price_id' => $priceId, 'created_at' => $now, 'updated_at' => $now]);
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
        $scopeId = substr(hash('sha256', $suffix), 0, 16);
        $accountId = DB::table('accounts')->insertGetId(['name' => "Reminder $suffix", 'email' => "$suffix-account@example.test", 'short_id' => "rem-$scopeId", 'currency_code' => 'USD', 'timezone' => 'UTC', 'created_at' => $now, 'updated_at' => $now]);
        $userId = DB::table('users')->insertGetId(['email' => "$suffix-user@example.test", 'password' => 'fixture', 'first_name' => 'Fixture', 'timezone' => 'UTC', 'created_at' => $now, 'updated_at' => $now]);
        $organizerId = DB::table('organizers')->insertGetId(['account_id' => $accountId, 'name' => 'Fixture', 'email' => "$suffix-organizer@example.test", 'currency' => 'USD', 'timezone' => 'UTC', 'created_at' => $now, 'updated_at' => $now]);
        $eventId = DB::table('events')->insertGetId(['account_id' => $accountId, 'organizer_id' => $organizerId, 'user_id' => $userId, 'title' => 'Fixture', 'short_id' => "evt-$scopeId", 'status' => 'LIVE', 'currency' => 'USD', 'timezone' => 'UTC', 'start_date' => now()->addDay(), 'end_date' => now()->addDays(2), 'created_at' => $now, 'updated_at' => $now]);
        $messageId = DB::table('messages')->insertGetId(['event_id' => $eventId, 'subject' => 'Reminder', 'message' => 'Fixture', 'type' => 'ALL_ATTENDEES', 'status' => 'PROCESSING', 'source' => 'EVENT_REMINDER', 'source_key' => "fixture:$suffix", 'created_at' => $now, 'updated_at' => $now]);
        $id = DB::table('event_reminder_occurrences')->insertGetId(['event_id' => $eventId, 'policy_version' => 'fixture', 'offset_key' => "fixture-$suffix", 'due_at_utc' => $now, 'source_event_start_at_utc' => now()->addDay(), 'source_event_timezone' => 'UTC', 'content_version' => 'fixture', 'theme_projection_version' => 'fixture', 'status' => EventReminderOccurrenceStatus::PLANNED->value, 'message_id' => $messageId, 'created_at' => $now, 'updated_at' => $now]);

        return [$eventId, $messageId, EventReminderOccurrence::query()->findOrFail($id)];
    }
}
