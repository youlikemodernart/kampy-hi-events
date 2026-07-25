<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_webhook_events', static function (Blueprint $table) {
            $table->id();
            $table->string('event_id')->unique();
            $table->string('event_type');
            $table->string('stripe_account_id')->nullable();
            $table->string('status', 20);
            $table->uuid('claim_token');
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('claimed_at')->nullable();
            $table->timestamp('handled_at')->nullable();
            $table->string('last_error_class')->nullable();
            $table->timestamps();

            $table->index(['status', 'claimed_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_events');
    }
};
