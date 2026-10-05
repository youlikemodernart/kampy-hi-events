<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // No historical backfill: current orders.email is receipt-editable, not provenance.
        Schema::create('order_purchase_contacts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('order_id')->unique();
            $table->text('email_encrypted');
            $table->timestamp('created_at');
            $table->foreign('order_id')->references('id')->on('orders')->cascadeOnDelete();
        });
        if (DB::getDriverName() === 'pgsql') {
            DB::unprepared(<<<'SQL'
CREATE FUNCTION reject_order_purchase_contact_update() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
    RAISE EXCEPTION 'Purchase contact is immutable';
END;
$$;
CREATE TRIGGER order_purchase_contact_immutable BEFORE UPDATE ON order_purchase_contacts
FOR EACH ROW EXECUTE FUNCTION reject_order_purchase_contact_update();
SQL);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('order_purchase_contacts');
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP FUNCTION IF EXISTS reject_order_purchase_contact_update()');
        }
    }
};
