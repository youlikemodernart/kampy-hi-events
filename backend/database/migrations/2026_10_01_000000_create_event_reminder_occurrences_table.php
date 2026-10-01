<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('event_reminder_occurrences', static function (Blueprint $table) {
            $table->id();
            $table->foreignId('event_id')->constrained()->cascadeOnDelete();
            $table->string('policy_version');
            $table->string('offset_key');
            $table->dateTime('due_at_utc');
            $table->dateTime('source_event_start_at_utc');
            $table->string('source_event_timezone');
            $table->string('content_version');
            $table->string('theme_projection_version');
            $table->string('status');
            $table->foreignId('message_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payload_digest', 64)->nullable();
            $table->unsignedInteger('expected_recipient_count')->nullable();
            $table->unsignedInteger('invalid_recipient_count')->nullable();
            $table->dateTime('audience_claimed_at')->nullable();
            $table->string('reason_code')->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'policy_version', 'offset_key'], 'event_reminder_occurrences_identity_unique');
            $table->index(['status', 'due_at_utc']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('event_reminder_occurrences');
    }
};
