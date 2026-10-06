<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
ALTER TABLE historical_receipt_recovery_cohorts ADD CONSTRAINT historical_receipt_purge_cutoff
 CHECK (purge_after=valid_until AND valid_until<=TIMESTAMPTZ '2026-10-17T20:00:00Z');
-- Only the nullable historical provenance link is detached; assignments survive.
ALTER TABLE gvsu_registration_assignments DROP CONSTRAINT gvsu_registration_assignments_recovery_evidence_id_fkey;
ALTER TABLE gvsu_registration_assignments ADD CONSTRAINT gvsu_registration_assignments_recovery_evidence_id_fkey
 FOREIGN KEY (recovery_evidence_id) REFERENCES order_receipt_recovery_evidence(id) ON DELETE SET NULL;
CREATE OR REPLACE FUNCTION guard_historical_receipt_records() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE cohort historical_receipt_recovery_cohorts;
BEGIN
 IF TG_TABLE_NAME='historical_receipt_recovery_cohorts' THEN
  IF TG_OP='INSERT' THEN
   IF CURRENT_TIMESTAMP >= TIMESTAMPTZ '2026-10-17T20:00:00Z' OR NEW.valid_until > TIMESTAMPTZ '2026-10-17T20:00:00Z' OR NEW.purge_after > TIMESTAMPTZ '2026-10-17T20:00:00Z' THEN RAISE EXCEPTION 'historical import window closed'; END IF;
   IF NEW.sealed_at IS NOT NULL OR NEW.revoked_at IS NOT NULL THEN RAISE EXCEPTION 'cohort must be assembled before sealing'; END IF;
   RETURN NEW;
  END IF;
  IF TG_OP='DELETE' THEN
   IF CURRENT_TIMESTAMP < TIMESTAMPTZ '2027-01-15T20:00:00Z' OR OLD.purge_after > CURRENT_TIMESTAMP OR
    EXISTS(SELECT 1 FROM order_receipt_recovery_evidence WHERE cohort_id=OLD.id)
    THEN RAISE EXCEPTION 'historical metadata retention active'; END IF;
   RETURN OLD;
  END IF;
  IF OLD.sealed_at IS NOT NULL AND (to_jsonb(NEW)-'revoked_at') IS DISTINCT FROM (to_jsonb(OLD)-'revoked_at') THEN RAISE EXCEPTION 'sealed cohort immutable'; END IF;
  IF OLD.revoked_at IS NOT NULL AND NEW.revoked_at IS DISTINCT FROM OLD.revoked_at THEN RAISE EXCEPTION 'revocation irreversible'; END IF;
  IF NEW.sealed_at IS NOT NULL AND OLD.sealed_at IS NULL AND (SELECT count(*) FROM order_receipt_recovery_evidence WHERE cohort_id=NEW.id)<>NEW.exact_order_count THEN RAISE EXCEPTION 'incomplete membership'; END IF;
  RETURN NEW;
 END IF;
 IF TG_OP='DELETE' THEN
  SELECT * INTO cohort FROM historical_receipt_recovery_cohorts WHERE id=OLD.cohort_id FOR UPDATE;
  IF CURRENT_TIMESTAMP < TIMESTAMPTZ '2027-01-15T20:00:00Z' OR cohort.purge_after > CURRENT_TIMESTAMP OR
   OLD.evidence_encrypted IS NOT NULL OR OLD.purged_at IS NULL OR
   EXISTS(SELECT 1 FROM respondent_confirmation_challenges WHERE authority_type='historical_receipt_v1' AND authority_id=OLD.id)
   THEN RAISE EXCEPTION 'historical metadata retention active'; END IF;
  RETURN OLD;
 END IF;
 SELECT * INTO cohort FROM historical_receipt_recovery_cohorts WHERE id=NEW.cohort_id FOR UPDATE;
 IF TG_OP='INSERT' THEN
  IF CURRENT_TIMESTAMP >= TIMESTAMPTZ '2026-10-17T20:00:00Z' OR cohort.sealed_at IS NOT NULL OR NOT EXISTS(SELECT 1 FROM orders WHERE id=NEW.order_id AND event_id=cohort.event_id) THEN RAISE EXCEPTION 'historical membership rejected'; END IF;
 ELSE
  IF NEW.evidence_encrypted IS NOT NULL OR OLD.evidence_encrypted IS NULL OR NEW.purged_at IS NULL OR cohort.purge_after>CURRENT_TIMESTAMP OR
   (to_jsonb(NEW)-'evidence_encrypted'-'purged_at') IS DISTINCT FROM (to_jsonb(OLD)-'evidence_encrypted'-'purged_at') OR
   EXISTS(SELECT 1 FROM respondent_confirmation_challenges WHERE authority_type='historical_receipt_v1' AND authority_id=OLD.id AND expires_at>CURRENT_TIMESTAMP)
   THEN RAISE EXCEPTION 'historical evidence immutable'; END IF;
 END IF;
 RETURN NEW;
END $$;
SQL);
    }

    public function down(): void
    {
        throw new \LogicException('Historical retention is forward-only; preserve expiry and evidence.');
    }
};
