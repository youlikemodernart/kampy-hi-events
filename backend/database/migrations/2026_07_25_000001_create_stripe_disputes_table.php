<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stripe_disputes', static function (Blueprint $table) {
            $table->id();
            $table->string('dispute_id')->unique();
            $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('stripe_payment_id')->nullable()->constrained('stripe_payments')->nullOnDelete();
            $table->string('payment_intent_id')->nullable()->index();
            $table->string('charge_id')->nullable()->index();
            $table->string('stripe_account_id')->nullable()->index();
            $table->bigInteger('amount_minor');
            $table->string('currency', 10);
            $table->string('status', 50);
            $table->string('reason', 100)->nullable();
            $table->jsonb('balance_transaction_ids');
            $table->timestamp('evidence_due_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('provider_created_at')->nullable();
            $table->string('last_event_id');
            $table->string('last_event_type');
            $table->timestamp('last_event_created_at');
            $table->timestamps();

            $table->index(['status', 'updated_at']);
            $table->index('last_event_created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_disputes');
    }
};
