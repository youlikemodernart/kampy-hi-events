<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE FUNCTION retire_historical_assignment_authority() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 -- The historical evidence DELETE guard must accept expiry/purge first.
 -- Both triggers and the FK action share the same atomic DELETE statement.
 UPDATE gvsu_registration_assignments SET status='expired' WHERE recovery_evidence_id=OLD.id;
 RETURN OLD;
END $$;
CREATE TRIGGER historical_evidence_retire_assignments BEFORE DELETE ON order_receipt_recovery_evidence
 FOR EACH ROW EXECUTE FUNCTION retire_historical_assignment_authority();
CREATE FUNCTION guard_expired_historical_assignment() RETURNS trigger LANGUAGE plpgsql AS $$
BEGIN
 IF OLD.status='expired' AND (NEW.status<>'expired' OR NEW.recovery_evidence_id IS NOT NULL) THEN
  RAISE EXCEPTION 'expired historical assignment authority cannot resume';
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER historical_assignment_expiry_guard BEFORE UPDATE ON gvsu_registration_assignments
 FOR EACH ROW EXECUTE FUNCTION guard_expired_historical_assignment();
REVOKE ALL ON FUNCTION retire_historical_assignment_authority(),guard_expired_historical_assignment() FROM PUBLIC;
SQL);
    }

    public function down(): void
    {
        throw new \LogicException('Historical expiry is forward-only; preserve terminal authority.');
    }
};
