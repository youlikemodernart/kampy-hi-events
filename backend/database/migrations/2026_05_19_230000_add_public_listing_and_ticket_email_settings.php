<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('event_settings', function (Blueprint $table) {
            $table->boolean('is_publicly_listed')->default(true)->after('allow_search_engine_indexing');
            $table->boolean('send_attendee_ticket_email')->default(true)->after('notify_organizer_of_new_orders');
        });
    }

    public function down(): void
    {
        Schema::table('event_settings', function (Blueprint $table) {
            $table->dropColumn([
                'is_publicly_listed',
                'send_attendee_ticket_email',
            ]);
        });
    }
};
