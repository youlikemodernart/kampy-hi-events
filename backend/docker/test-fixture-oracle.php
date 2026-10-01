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
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public'
          AND indexname = 'event_reminder_occurrences_identity_unique'
          AND indexdef LIKE 'CREATE UNIQUE INDEX%'
    ) AS occurrence_identity_unique,
    EXISTS (
        SELECT 1 FROM pg_indexes
        WHERE schemaname = 'public'
          AND indexname = 'outgoing_messages_normalized_recipient_unique'
          AND indexdef LIKE 'CREATE UNIQUE INDEX%'
    ) AS recipient_identity_unique,
    EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_schema = 'public' AND table_name = 'messages'
          AND column_name = 'sent_by_user_id' AND is_nullable = 'YES'
    ) AS nullable_system_message,
    EXISTS (
        SELECT 1 FROM information_schema.table_constraints
        WHERE table_schema = 'public' AND table_name = 'event_reminder_occurrences'
          AND constraint_type = 'FOREIGN KEY'
    ) AS occurrence_foreign_key,
    (SELECT count(*) FROM migrations) > 0 AS migrations_recorded
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
