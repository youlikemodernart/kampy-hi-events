<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gvsu_registration_assignments', static function (Blueprint $table): void {
            $table->id();
            $table->string('provision_batch_id', 80)->nullable();
            $table->unsignedBigInteger('event_id');
            $table->unsignedBigInteger('order_id');
            $table->unsignedBigInteger('attendee_id');
            $table->string('attendee_public_id', 128);
            $table->string('respondent_id', 80);
            $table->string('assignment_id', 80);
            $table->unsignedInteger('assignment_revision');
            $table->string('attendee_display_name', 200);
            $table->string('respondent_display_name', 200);
            $table->string('respondent_route', 16);
            $table->string('guardian_relationship_reference', 80)->nullable();
            $table->char('respondent_identity_digest_sha256', 64);
            $table->longText('delivery_destination_ciphertext');
            $table->char('payload_digest_sha256', 64)->nullable();
            $table->char('delivery_email_hmac_sha256', 64);
            $table->string('replaced_assignment_id', 80)->nullable();
            $table->string('status', 24);
            $table->timestamp('bound_at');
            $table->timestamp('corrected_at')->nullable();
            $table->timestamp('link_replacement_requested_at')->nullable();
            $table->timestamp('link_replacement_delivered_at')->nullable();
            $table->timestamp('attempted_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('unknown_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'attendee_id'], 'gvsu_registration_assignment_attendee_unique');
            $table->unique('assignment_id');
            $table->index(['provision_batch_id', 'status']);
            $table->foreign('event_id')->references('id')->on('events')->cascadeOnDelete();
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
            $table->foreign('attendee_id')->references('id')->on('attendees')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gvsu_registration_assignments');
    }
};
