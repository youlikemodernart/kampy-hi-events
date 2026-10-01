<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('messages', static function (Blueprint $table) {
            $table->foreignId('sent_by_user_id')->nullable()->change();
            $table->string('source')->default('OPERATOR');
            $table->string('source_key')->nullable()->unique();
        });

        Schema::table('outgoing_messages', static function (Blueprint $table) {
            $table->string('recipient_normalized')->nullable();
            $table->foreignId('attendee_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payload_digest', 64)->nullable();
            $table->unsignedInteger('attempt_count')->default(0);
            $table->string('last_error_class')->nullable();
            $table->string('provider_message_id')->nullable();
            $table->dateTime('provider_accepted_at')->nullable();
            $table->dateTime('claimed_at')->nullable();
            $table->dateTime('submitted_at')->nullable();
            $table->unique(['message_id', 'recipient_normalized'], 'outgoing_messages_normalized_recipient_unique');
        });
    }

    public function down(): void
    {
        throw new LogicException(
            'This migration is intentionally non-reversible once system-authored reminder messages exist. '
            .'Use forward recovery or a separately reviewed data cleanup that removes those messages before restoring NOT NULL actors.'
        );
    }
};
