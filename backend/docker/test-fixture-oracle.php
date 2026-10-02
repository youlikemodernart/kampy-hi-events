<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

const JUNIT_LIMIT = 1_000_000;
const EXPECTED_SUITE = 'Tests\\Feature\\Message\\EventReminderLifecyclePostgresTest';

$junit = $argv[1] ?? '';
if ($junit === '' || ! is_file($junit) || filesize($junit) === false || filesize($junit) > JUNIT_LIMIT) {
    exit(20);
}

require '/work/backend/vendor/autoload.php';
$app = require '/work/backend/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$reader = new XMLReader;
if (! $reader->open($junit, null, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) {
    exit(21);
}

$suite = null;
while ($reader->read()) {
    if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'testsuite'
        && $reader->getAttribute('name') === EXPECTED_SUITE) {
        $suite = [
            'name' => $reader->getAttribute('name'),
            'tests' => (int) $reader->getAttribute('tests'),
            'assertions' => (int) $reader->getAttribute('assertions'),
            'failures' => (int) $reader->getAttribute('failures'),
            'errors' => (int) $reader->getAttribute('errors'),
            'skipped' => (int) $reader->getAttribute('skipped'),
        ];
        break;
    }
}
$reader->close();
if ($suite === null || $suite['tests'] < 1 || $suite['assertions'] < 1
    || $suite['failures'] !== 0 || $suite['errors'] !== 0 || $suite['skipped'] !== 0) {
    exit(22);
}

$serverVersion = (string) DB::selectOne('SHOW server_version')->server_version;
$schema = DB::selectOne(<<<'SQL'
SELECT
    to_regclass('public.event_reminder_occurrences') IS NOT NULL AS occurrence_table,
    to_regclass('public.messages') IS NOT NULL AS messages_table,
    to_regclass('public.outgoing_messages') IS NOT NULL AS outgoing_table,
    EXISTS (
        SELECT 1
        FROM pg_index index_row
        JOIN pg_class index_class ON index_class.oid = index_row.indexrelid
        JOIN pg_class table_class ON table_class.oid = index_row.indrelid
        JOIN pg_namespace table_namespace ON table_namespace.oid = table_class.relnamespace
        WHERE table_namespace.nspname = 'public'
          AND table_class.relname = 'event_reminder_occurrences'
          AND index_class.relname = 'event_reminder_occurrences_identity_unique'
          AND index_row.indisunique AND index_row.indisvalid
          AND index_row.indpred IS NULL AND index_row.indexprs IS NULL
          AND index_row.indnatts = 3 AND index_row.indnkeyatts = 3
          AND (SELECT array_agg(attribute.attname::text ORDER BY key.position)
               FROM unnest(index_row.indkey::smallint[]) WITH ORDINALITY key(attnum, position)
               JOIN pg_attribute attribute ON attribute.attrelid = index_row.indrelid AND attribute.attnum = key.attnum)
              = ARRAY['event_id', 'policy_version', 'offset_key']
    ) AS occurrence_identity_unique,
    EXISTS (
        SELECT 1
        FROM pg_index index_row
        JOIN pg_class index_class ON index_class.oid = index_row.indexrelid
        JOIN pg_class table_class ON table_class.oid = index_row.indrelid
        JOIN pg_namespace table_namespace ON table_namespace.oid = table_class.relnamespace
        WHERE table_namespace.nspname = 'public'
          AND table_class.relname = 'outgoing_messages'
          AND index_class.relname = 'outgoing_messages_normalized_recipient_unique'
          AND index_row.indisunique AND index_row.indisvalid
          AND index_row.indpred IS NULL AND index_row.indexprs IS NULL
          AND index_row.indnatts = 2 AND index_row.indnkeyatts = 2
          AND (SELECT array_agg(attribute.attname::text ORDER BY key.position)
               FROM unnest(index_row.indkey::smallint[]) WITH ORDINALITY key(attnum, position)
               JOIN pg_attribute attribute ON attribute.attrelid = index_row.indrelid AND attribute.attnum = key.attnum)
              = ARRAY['message_id', 'recipient_normalized']
    ) AS recipient_identity_unique,
    EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'messages'
          AND column_name = 'sent_by_user_id' AND data_type = 'bigint' AND is_nullable = 'YES'
    ) AS nullable_system_message,
    EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'event_reminder_occurrences'
          AND column_name = 'payload_digest' AND data_type = 'character varying'
          AND character_maximum_length = 64 AND is_nullable = 'YES'
    ) AND EXISTS (
        SELECT 1
        FROM pg_attribute attribute
        JOIN pg_class table_class ON table_class.oid = attribute.attrelid
        JOIN pg_namespace table_namespace ON table_namespace.oid = table_class.relnamespace
        JOIN pg_attrdef default_row ON default_row.adrelid = table_class.oid AND default_row.adnum = attribute.attnum
        WHERE table_namespace.nspname = 'public' AND table_class.relname = 'outgoing_messages'
          AND attribute.attname = 'attempt_count' AND attribute.atttypid = 'integer'::regtype
          AND attribute.attnotnull AND pg_get_expr(default_row.adbin, default_row.adrelid) = '0'
    ) AS reminder_column_contract,
    EXISTS (
        SELECT 1
        FROM pg_constraint constraint_row
        JOIN pg_class source ON source.oid = constraint_row.conrelid
        JOIN pg_namespace source_namespace ON source_namespace.oid = source.relnamespace
        JOIN pg_class target ON target.oid = constraint_row.confrelid
        JOIN pg_namespace target_namespace ON target_namespace.oid = target.relnamespace
        JOIN pg_attribute source_column ON source_column.attrelid = source.oid
          AND source_column.attnum = constraint_row.conkey[1]
        JOIN pg_attribute target_column ON target_column.attrelid = target.oid
          AND target_column.attnum = constraint_row.confkey[1]
        WHERE constraint_row.contype = 'f' AND source_namespace.nspname = 'public'
          AND target_namespace.nspname = 'public' AND source.relname = 'event_reminder_occurrences'
          AND cardinality(constraint_row.conkey) = 1 AND cardinality(constraint_row.confkey) = 1
          AND source_column.attname = 'event_id' AND target.relname = 'events'
          AND target_column.attname = 'id' AND constraint_row.confdeltype = 'c'
    ) AND EXISTS (
        SELECT 1
        FROM pg_constraint constraint_row
        JOIN pg_class source ON source.oid = constraint_row.conrelid
        JOIN pg_namespace source_namespace ON source_namespace.oid = source.relnamespace
        JOIN pg_class target ON target.oid = constraint_row.confrelid
        JOIN pg_namespace target_namespace ON target_namespace.oid = target.relnamespace
        JOIN pg_attribute source_column ON source_column.attrelid = source.oid
          AND source_column.attnum = constraint_row.conkey[1]
        JOIN pg_attribute target_column ON target_column.attrelid = target.oid
          AND target_column.attnum = constraint_row.confkey[1]
        WHERE constraint_row.contype = 'f' AND source_namespace.nspname = 'public'
          AND target_namespace.nspname = 'public' AND source.relname = 'event_reminder_occurrences'
          AND cardinality(constraint_row.conkey) = 1 AND cardinality(constraint_row.confkey) = 1
          AND source_column.attname = 'message_id' AND target.relname = 'messages'
          AND target_column.attname = 'id' AND constraint_row.confdeltype = 'n'
    ) AS occurrence_foreign_keys_exact,
    (SELECT count(*) FILTER (WHERE migration = '2026_10_01_000000_create_event_reminder_occurrences_table') = 1
         AND count(*) FILTER (WHERE migration = '2026_10_01_000001_add_reminder_identity_to_messages_and_outgoing_messages') = 1
     FROM migrations
     WHERE migration IN (
         '2026_10_01_000000_create_event_reminder_occurrences_table',
         '2026_10_01_000001_add_reminder_identity_to_messages_and_outgoing_messages'
     )) AS reminder_migrations_recorded
SQL);

$schemaChecks = array_map(static fn (mixed $value): bool => filter_var($value, FILTER_VALIDATE_BOOL), (array) $schema);
if (! str_starts_with($serverVersion, '17.') || in_array(false, $schemaChecks, true)) {
    exit(23);
}

$result = [
    'oracle' => 'kampy-event-reminder-postgres-v1',
    'php' => PHP_VERSION,
    'pdo_pgsql' => extension_loaded('pdo_pgsql'),
    'postgres' => $serverVersion,
    'suite' => $suite,
    'schema' => $schemaChecks,
];
echo json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES), "\n";
