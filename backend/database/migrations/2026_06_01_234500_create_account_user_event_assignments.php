<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('account_user_event_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('account_user_id')->constrained('account_users')->onDelete('cascade');
            $table->foreignId('event_id')->constrained('events')->onDelete('cascade');
            $table->foreignId('created_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
            $table->softDeletes();

            $table->index('account_user_id');
            $table->index('event_id');
        });

        DB::statement(
            'CREATE UNIQUE INDEX account_user_event_assignments_active_unique '
            . 'ON account_user_event_assignments (account_user_id, event_id) '
            . 'WHERE deleted_at IS NULL'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('account_user_event_assignments');
    }
};
