<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateRefund = DB::table('order_refunds')
            ->select('payment_provider', 'refund_id')
            ->groupBy('payment_provider', 'refund_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicateRefund !== null) {
            throw new \RuntimeException(
                'Cannot enforce Stripe refund identity uniqueness until duplicate rows are reconciled.'
            );
        }

        Schema::table('order_refunds', static function (Blueprint $table): void {
            $table->unique(
                ['payment_provider', 'refund_id'],
                'order_refunds_provider_refund_id_unique',
            );
        });

        Schema::create('stripe_refund_requests', static function (Blueprint $table): void {
            $table->id();
            $table->uuid('request_id')->unique();
            $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
            $table->foreignId('stripe_payment_id')->constrained('stripe_payments')->cascadeOnDelete();
            $table->string('payment_intent_id');
            $table->string('stripe_account_id')->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->string('currency', 10);
            $table->boolean('notify_buyer');
            $table->boolean('cancel_order');
            $table->boolean('refund_application_fee')->nullable();
            $table->string('status', 32);
            $table->unsignedInteger('attempts')->default(0);
            $table->string('provider_refund_id')->nullable()->unique();
            $table->string('provider_status', 32)->nullable();
            $table->string('last_error_class')->nullable();
            $table->timestamp('last_attempted_at')->nullable();
            $table->timestamp('provider_accepted_at')->nullable();
            $table->timestamp('cancel_applied_at')->nullable();
            $table->timestamp('notification_claimed_at')->nullable();
            $table->timestamp('notification_sent_at')->nullable();
            $table->timestamps();

            $table->index(['order_id', 'status']);
            $table->index('payment_intent_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stripe_refund_requests');

        Schema::table('order_refunds', static function (Blueprint $table): void {
            $table->dropUnique('order_refunds_provider_refund_id_unique');
        });
    }
};
