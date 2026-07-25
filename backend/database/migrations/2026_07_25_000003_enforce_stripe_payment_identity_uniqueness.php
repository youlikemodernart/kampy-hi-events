<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $duplicateOrder = DB::table('stripe_payments')
            ->select('order_id')
            ->groupBy('order_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();
        $duplicatePaymentIntent = DB::table('stripe_payments')
            ->select('payment_intent_id')
            ->groupBy('payment_intent_id')
            ->havingRaw('COUNT(*) > 1')
            ->first();

        if ($duplicateOrder !== null || $duplicatePaymentIntent !== null) {
            throw new \RuntimeException(
                'Cannot enforce Stripe payment identity uniqueness until duplicate rows are reconciled.'
            );
        }

        Schema::table('stripe_payments', static function (Blueprint $table): void {
            $table->unique('order_id', 'stripe_payments_order_id_unique');
            $table->unique('payment_intent_id', 'stripe_payments_payment_intent_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('stripe_payments', static function (Blueprint $table): void {
            $table->dropUnique('stripe_payments_order_id_unique');
            $table->dropUnique('stripe_payments_payment_intent_id_unique');
        });
    }
};
