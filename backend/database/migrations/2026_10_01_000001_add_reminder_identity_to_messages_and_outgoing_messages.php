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
        Schema::table('outgoing_messages', static function (Blueprint $table) {
            $table->dropUnique('outgoing_messages_normalized_recipient_unique');
            $table->dropForeign(['attendee_id']);
            $table->dropColumn([
                'recipient_normalized', 'attendee_id', 'payload_digest', 'attempt_count', 'last_error_class',
                'provider_message_id', 'provider_accepted_at', 'claimed_at', 'submitted_at',
            ]);
        });

        Schema::table('messages', static function (Blueprint $table) {
            $table->dropUnique(['source_key']);
            $table->dropColumn(['source', 'source_key']);
            $table->foreignId('sent_by_user_id')->nullable(false)->change();
        });
    }
};
