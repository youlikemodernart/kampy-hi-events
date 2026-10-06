<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('gvsu_registration_assignments', function (Blueprint $table) {
            $table->boolean('completion_invitation')->default(false);
        });
        Schema::table('respondent_confirmation_challenges', function (Blueprint $table) {
            $table->boolean('invitation')->default(false);
        });
    }

    public function down(): void
    {
        Schema::table('gvsu_registration_assignments', fn (Blueprint $table) => $table->dropColumn('completion_invitation'));
        Schema::table('respondent_confirmation_challenges', fn (Blueprint $table) => $table->dropColumn('invitation'));
    }
};
