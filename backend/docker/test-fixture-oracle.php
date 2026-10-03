<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

const JUNIT_LIMIT = 1_000_000;
const EXPECTED_SUITE = 'Tests\\Feature\\Message\\EventReminderLifecyclePostgresTest';

// Bootstrap may install its own handlers; never forward its output or exception text.
ini_set('display_errors', '0');
ini_set('log_errors', '0');
ob_start(static fn (string $output): string => '');
$stage = 'junit';
$finished = false;
function fixtureMark(string $stage, string $state, string $category = 'none', int $code = 0): void
{
    fwrite(STDOUT, "KAMPY_STAGE_V1\t$stage\t$state\t$category\t$code\n");
}
function fixtureFail(string $stage, string $category, int $code): never
{
    global $finished;
    $finished = true;
    fixtureMark($stage, 'failed', $category, $code);
    exit($code);
}
register_shutdown_function(static function () use (&$finished, &$stage): void {
    if (! $finished) {
        fixtureMark($stage, 'failed', 'oracle-aborted', 24);
        exit(24);
    }
});

try {
    fixtureMark($stage, 'attempted');
    $junit = $argv[1] ?? '';
    if ($junit === '' || ! is_file($junit) || filesize($junit) === false || filesize($junit) > JUNIT_LIMIT) {
        fixtureFail($stage, 'artifact-unavailable', 20);
    }
    libxml_use_internal_errors(true);
    $reader = new XMLReader;
    if (! $reader->open($junit, null, LIBXML_NONET | LIBXML_NOBLANKS | LIBXML_COMPACT)) {
        fixtureFail($stage, 'junit-invalid', 21);
    }
    $suite = null;
    while ($reader->read()) {
        if ($reader->nodeType === XMLReader::DOC_TYPE) {
            fixtureFail($stage, 'junit-invalid', 21);
        }
        if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'testsuite'
            && $reader->getAttribute('name') === EXPECTED_SUITE) {
            if ($suite !== null) {
                fixtureFail($stage, 'junit-invalid', 21);
            }
            $suite = ['name' => EXPECTED_SUITE];
            foreach (['tests', 'assertions', 'failures', 'errors', 'skipped'] as $key) {
                $value = $reader->getAttribute($key);
                if ($value === null || ! preg_match('/^[0-9]{1,7}$/D', $value)) {
                    fixtureFail($stage, 'junit-invalid', 21);
                }
                $suite[$key] = (int) $value;
            }
        }
    }
    $reader->close();
    if (libxml_get_errors() !== [] || $suite === null) {
        fixtureFail($stage, 'junit-invalid', 21);
    }
    fwrite(STDOUT, "KAMPY_COUNTS_V1\t".implode("\t", array_slice($suite, 1))."\n");
    fixtureMark($stage, 'complete');
    // Failed tests still yield safe counts, but must not bootstrap or claim acceptance.
    if (($argv[2] ?? '0') !== '0') {
        $finished = true;
        exit(0);
    }
    if ($suite['tests'] < 1 || $suite['assertions'] < 1 || $suite['failures'] !== 0
        || $suite['errors'] !== 0 || $suite['skipped'] !== 0) {
        fixtureFail($stage, 'junit-rejected', 22);
    }

    $stage = 'bootstrap';
    fixtureMark($stage, 'attempted');
    require '/work/backend/vendor/autoload.php';
    $app = require '/work/backend/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();
    fixtureMark($stage, 'complete');

    $stage = 'database';
    fixtureMark($stage, 'attempted');
    $serverVersion = (string) DB::selectOne('SHOW server_version')->server_version;
    if (! preg_match('/^17(?:\.[0-9]+)+$/D', $serverVersion)) {
        fixtureFail($stage, 'database-version-refused', 23);
    }
    fixtureMark($stage, 'complete');
    $stage = 'schema';
    fixtureMark($stage, 'attempted');
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
    if (in_array(false, $schemaChecks, true)) {
        fixtureFail($stage, 'schema-rejected', 23);
    }
    fixtureMark($stage, 'complete');
    $stage = 'serialization';
    fixtureMark($stage, 'attempted');
    $result = [
        'oracle' => 'kampy-event-reminder-postgres-v1',
        'php' => PHP_VERSION,
        'pdo_pgsql' => extension_loaded('pdo_pgsql'),
        'postgres' => $serverVersion,
        'suite' => $suite,
        'schema' => $schemaChecks,
    ];
    $encoded = json_encode($result, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    fwrite(STDOUT, $encoded."\n");
    fixtureMark($stage, 'complete');
    $finished = true;
} catch (Throwable $error) {
    fixtureFail($stage, 'oracle-exception', 24);
}
