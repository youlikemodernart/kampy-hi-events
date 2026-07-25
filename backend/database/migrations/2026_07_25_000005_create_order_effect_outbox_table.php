<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_effect_outbox', static function (Blueprint $table): void {
            $table->id();
            $table->string('delivery_id', 80)->unique();
            $table->string('business_key', 255)->unique();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->string('effect_type', 40);
            $table->string('transition_key', 80);
            $table->string('domain_event_type', 80)->nullable();
            $table->string('email_kind', 40)->nullable();
            $table->string('status', 24);
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('available_at');
            $table->timestamp('claimed_at')->nullable();
            $table->uuid('claim_token')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('manual_review_at')->nullable();
            $table->string('last_error_class')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at', 'id'], 'order_effect_outbox_claim_index');
            $table->index(['status', 'claimed_at'], 'order_effect_outbox_stale_index');
            $table->index(['order_id', 'transition_key']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_effect_outbox');
    }
};
