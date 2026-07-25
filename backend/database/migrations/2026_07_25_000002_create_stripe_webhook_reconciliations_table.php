<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_webhook_reconciliations', static function (Blueprint $table): void {
            $table->id();
            $table->string('event_id');
            $table->string('event_type');
            $table->string('stripe_account_id')->nullable()->index();
            $table->string('provider_object_type', 30);
            $table->string('provider_object_id');
            $table->string('payment_intent_id')->nullable()->index();
            $table->string('charge_id')->nullable()->index();
            $table->string('refund_id')->nullable()->index();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stripe_payment_id')->nullable()->constrained('stripe_payments')->nullOnDelete();
            $table->string('reason_code', 80);
            $table->string('status', 30);
            $table->unsignedInteger('attempts')->default(1);
            $table->timestamp('first_seen_at');
            $table->timestamp('last_seen_at');
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('manual_review_at')->nullable();
            $table->string('last_error_class')->nullable();
            $table->timestamps();

            $table->foreign('event_id')
                ->references('event_id')
                ->on('stripe_webhook_events')
                ->cascadeOnDelete();
            $table->unique(
                ['event_id', 'provider_object_type', 'provider_object_id'],
                'stripe_webhook_reconciliation_identity_unique',
            );
            $table->index(['status', 'first_seen_at'], 'stripe_webhook_reconciliation_aging_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_webhook_reconciliations');
    }
};
