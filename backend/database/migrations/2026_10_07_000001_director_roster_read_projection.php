<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(file_get_contents(database_path('roster/director_roster_v1.sql')));
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP FUNCTION public.kampy_roster_contact_v1(text,bigint,bigint,bigint,text[]);
DROP FUNCTION public.kampy_roster_event_snapshot_v1(text,bigint,bigint,integer);
DROP FUNCTION public.kampy_roster_event_allowed_v1(text,bigint,bigint);
DROP TABLE public.kampy_roster_approved_events;
DROP OWNED BY kampy_native_roster_reader, kampy_native_roster_owner;
DROP ROLE kampy_native_roster_reader;
DROP ROLE kampy_native_roster_owner;
SQL);
    }
};
