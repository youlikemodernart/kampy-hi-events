<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('respondent_confirmation_destinations', function (Blueprint $table) {
            $table->id();
            $table->char('destination_digest', 64)->unique();
            $table->timestamp('last_requested_at')->nullable();
            $table->timestamp('window_started_at')->nullable();
            $table->unsignedInteger('request_count')->default(0);
        });
        Schema::create('respondent_confirmation_challenges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->index();
            $table->char('destination_digest', 64);
            $table->char('token_digest', 64)->unique();
            $table->timestamp('created_at');
            $table->timestamp('expires_at')->index();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestamp('consumed_at')->nullable();
            $table->char('confirmation_digest', 64)->nullable();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->index('destination_digest');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('respondent_confirmation_challenges');
        Schema::dropIfExists('respondent_confirmation_destinations');
    }
};
