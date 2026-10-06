<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared(<<<'SQL'
CREATE TABLE historical_receipt_recovery_cohorts (
 id bigserial PRIMARY KEY, event_id bigint NOT NULL CHECK(event_id=7), account_id bigint NOT NULL CHECK(account_id=1), organizer_id bigint NOT NULL CHECK(organizer_id=2),
 manifest_digest char(64) NOT NULL UNIQUE, scope_json text NOT NULL, exact_order_count integer NOT NULL CHECK(exact_order_count BETWEEN 1 AND 150),
 predicate_version text NOT NULL CHECK(predicate_version='historical_receipt_v1'), source_revision text NOT NULL,
 selected_at timestamptz NOT NULL, approved_effect_reference text NOT NULL,
 valid_until timestamptz NOT NULL, purge_after timestamptz NOT NULL, sealed_at timestamptz, revoked_at timestamptz,
 CHECK(selected_at<valid_until AND valid_until<=purge_after)
);
CREATE TABLE order_receipt_recovery_evidence (
 id bigserial PRIMARY KEY, cohort_id bigint NOT NULL REFERENCES historical_receipt_recovery_cohorts(id),
 order_id bigint NOT NULL UNIQUE REFERENCES orders(id), event_id bigint NOT NULL CHECK(event_id=7),
 authority_type text NOT NULL CHECK(authority_type='historical_receipt_v1'), evidence_encrypted text,
 evidence_commitment char(64) NOT NULL, encryption_key_version text NOT NULL, integrity_key_version text NOT NULL,
 receipt_identity_digest char(64) NOT NULL UNIQUE, observed_at timestamptz NOT NULL, purged_at timestamptz,
 CHECK((evidence_encrypted IS NULL)=(purged_at IS NOT NULL))
);
ALTER TABLE respondent_confirmation_challenges ADD COLUMN authority_type text,
 ADD COLUMN authority_id bigint, ADD COLUMN authority_commitment char(64), ADD COLUMN verified_at timestamptz, ADD COLUMN verified_context_digest char(64);
UPDATE respondent_confirmation_challenges SET expires_at=LEAST(expires_at, CURRENT_TIMESTAMP);
ALTER TABLE gvsu_registration_assignments ADD COLUMN recovery_evidence_id bigint REFERENCES order_receipt_recovery_evidence(id);
CREATE FUNCTION guard_historical_receipt_records() RETURNS trigger LANGUAGE plpgsql AS $$
DECLARE cohort historical_receipt_recovery_cohorts;
BEGIN
 IF TG_TABLE_NAME='historical_receipt_recovery_cohorts' THEN
  IF TG_OP='INSERT' THEN
   IF NEW.sealed_at IS NOT NULL OR NEW.revoked_at IS NOT NULL THEN RAISE EXCEPTION 'cohort must be assembled before sealing'; END IF;
   RETURN NEW;
  END IF;
  IF TG_OP='DELETE' THEN RAISE EXCEPTION 'historical tombstones cannot be deleted'; END IF;
  IF OLD.sealed_at IS NOT NULL AND (to_jsonb(NEW)-'revoked_at') IS DISTINCT FROM (to_jsonb(OLD)-'revoked_at') THEN RAISE EXCEPTION 'sealed cohort immutable'; END IF;
  IF OLD.revoked_at IS NOT NULL AND NEW.revoked_at IS DISTINCT FROM OLD.revoked_at THEN RAISE EXCEPTION 'revocation irreversible'; END IF;
  IF NEW.sealed_at IS NOT NULL AND OLD.sealed_at IS NULL AND (SELECT count(*) FROM order_receipt_recovery_evidence WHERE cohort_id=NEW.id)<>NEW.exact_order_count THEN RAISE EXCEPTION 'incomplete membership'; END IF;
  RETURN NEW;
 END IF;
 IF TG_OP='DELETE' THEN RAISE EXCEPTION 'historical tombstones cannot be deleted'; END IF;
 SELECT * INTO cohort FROM historical_receipt_recovery_cohorts WHERE id=NEW.cohort_id FOR UPDATE;
 IF TG_OP='INSERT' THEN
  IF cohort.sealed_at IS NOT NULL OR NOT EXISTS(SELECT 1 FROM orders WHERE id=NEW.order_id AND event_id=cohort.event_id) THEN RAISE EXCEPTION 'historical membership rejected'; END IF;
 ELSE
  IF NEW.evidence_encrypted IS NOT NULL OR OLD.evidence_encrypted IS NULL OR NEW.purged_at IS NULL OR cohort.purge_after>CURRENT_TIMESTAMP OR
   (to_jsonb(NEW)-'evidence_encrypted'-'purged_at') IS DISTINCT FROM (to_jsonb(OLD)-'evidence_encrypted'-'purged_at') OR
   EXISTS(SELECT 1 FROM respondent_confirmation_challenges WHERE authority_type='historical_receipt_v1' AND authority_id=OLD.id AND expires_at>CURRENT_TIMESTAMP)
   THEN RAISE EXCEPTION 'historical evidence immutable'; END IF;
 END IF;
 RETURN NEW;
END $$;
CREATE TRIGGER historical_cohort_guard BEFORE INSERT OR UPDATE OR DELETE ON historical_receipt_recovery_cohorts FOR EACH ROW EXECUTE FUNCTION guard_historical_receipt_records();
CREATE TRIGGER historical_evidence_guard BEFORE INSERT OR UPDATE OR DELETE ON order_receipt_recovery_evidence FOR EACH ROW EXECUTE FUNCTION guard_historical_receipt_records();
REVOKE ALL ON historical_receipt_recovery_cohorts,order_receipt_recovery_evidence FROM PUBLIC;
REVOKE ALL ON FUNCTION guard_historical_receipt_records() FROM PUBLIC;
SQL);
    }

    public function down(): void
    {
        DB::unprepared('ALTER TABLE gvsu_registration_assignments DROP COLUMN recovery_evidence_id; ALTER TABLE respondent_confirmation_challenges DROP COLUMN authority_type, DROP COLUMN authority_id, DROP COLUMN authority_commitment, DROP COLUMN verified_at, DROP COLUMN verified_context_digest; DROP TABLE order_receipt_recovery_evidence; DROP TABLE historical_receipt_recovery_cohorts; DROP FUNCTION guard_historical_receipt_records();');
    }
};
