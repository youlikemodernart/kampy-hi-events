<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('financial_scopes', static function (Blueprint $table): void {
            $table->id();
            $table->string('scope_key', 64)->unique();
            $table->foreignId('account_id')->constrained('accounts')->restrictOnDelete();
            $table->foreignId('organizer_id')->constrained('organizers')->restrictOnDelete();
            $table->foreignId('event_id')->constrained('events')->restrictOnDelete();
            $table->string('university_id', 120);
            $table->string('cycle_id', 120);
            $table->string('timezone');
            $table->char('currency', 3);
            $table->timestampTz('recorded_at')->useCurrent();

            $table->unique(
                ['account_id', 'organizer_id', 'event_id', 'university_id', 'cycle_id'],
                'financial_scope_identity_unique',
            );
            $table->index(['account_id', 'university_id', 'cycle_id'], 'financial_scope_account_index');
            $table->index(['event_id', 'university_id', 'cycle_id'], 'financial_scope_event_index');
        });

        Schema::create('financial_source_mapping_revisions', static function (Blueprint $table): void {
            $table->id();
            $table->string('mapping_revision_id', 64)->unique();
            $table->string('mapping_key', 64);
            $table->foreignId('financial_scope_id')->constrained('financial_scopes')->restrictOnDelete();
            $table->unsignedInteger('revision_number');
            $table->string('source_system', 32);
            $table->string('source_namespace');
            $table->string('source_object_kind', 32);
            $table->string('source_object_id');
            $table->string('disposition', 16);
            $table->string('supersedes_mapping_revision_id', 64)->nullable();
            $table->string('content_fingerprint', 64);
            $table->timestampTz('effective_at');
            $table->timestampTz('recorded_at');

            $table->unique(['mapping_key', 'revision_number'], 'financial_mapping_revision_unique');
            $table->index(
                ['financial_scope_id', 'source_system', 'source_object_kind'],
                'financial_mapping_scope_index',
            );
        });

        Schema::table('financial_source_mapping_revisions', static function (Blueprint $table): void {
            $table->foreign('supersedes_mapping_revision_id', 'financial_mapping_predecessor_fk')
                ->references('mapping_revision_id')
                ->on('financial_source_mapping_revisions')
                ->restrictOnDelete();
        });

        Schema::create('financial_snapshots', static function (Blueprint $table): void {
            $table->id();
            $table->string('snapshot_id', 64)->unique();
            $table->string('stream_key', 64);
            $table->string('source_version_key', 64);
            $table->foreignId('financial_scope_id')->constrained('financial_scopes')->restrictOnDelete();
            $table->string('snapshot_kind', 32);
            $table->string('source_system', 32);
            $table->string('source_namespace');
            $table->string('adapter_version', 80);
            $table->timestampTz('source_as_of_at');
            $table->timestampTz('imported_at');
            $table->string('policy_version', 80)->nullable();
            $table->string('content_fingerprint', 64);
            $table->string('reconciliation_status', 24);
            $table->boolean('source_publishable');
            $table->boolean('policy_publishable');
            $table->unsignedBigInteger('record_count');
            $table->jsonb('summary_json');
            $table->timestampTz('recorded_at')->useCurrent();

            $table->unique(
                ['source_version_key', 'content_fingerprint'],
                'financial_snapshot_source_version_unique',
            );
            $table->index(
                ['financial_scope_id', 'snapshot_kind', 'stream_key', 'source_as_of_at'],
                'financial_snapshot_scope_stream_index',
            );
        });

        Schema::create('financial_snapshot_records', static function (Blueprint $table): void {
            $table->id();
            $table->string('snapshot_record_id', 64)->unique();
            $table->string('snapshot_id', 64);
            $table->unsignedBigInteger('record_ordinal');
            $table->string('mapping_revision_id', 64);
            $table->string('source_identity_key', 64);
            $table->string('content_fingerprint', 64);
            $table->string('provider_status', 80);
            $table->string('financial_status', 80);
            $table->string('recognition_disposition', 80)->nullable();
            $table->string('source_completeness_status', 80)->nullable();
            $table->string('source_method', 80)->nullable();
            $table->char('currency', 3);
            $table->unsignedBigInteger('quantity');
            $table->unsignedBigInteger('gross_cents')->nullable();
            $table->unsignedBigInteger('processor_fee_cents')->nullable();
            $table->unsignedBigInteger('processor_fee_refund_cents')->nullable();
            $table->string('processor_fee_provenance', 16)->nullable();
            $table->unsignedBigInteger('platform_fee_cents')->nullable();
            $table->unsignedBigInteger('platform_fee_refund_cents')->nullable();
            $table->string('platform_fee_provenance', 16)->nullable();
            $table->unsignedBigInteger('refund_cents')->nullable();
            $table->unsignedBigInteger('payment_reversal_cents')->nullable();
            $table->unsignedBigInteger('dispute_fee_cents')->nullable();
            $table->bigInteger('provider_net_cents')->nullable();
            $table->bigInteger('net_settlement_cents')->nullable();
            $table->string('settlement_semantic_status', 80)->nullable();
            $table->timestampTz('source_occurred_at');
            $table->timestampTz('source_updated_at');
            $table->timestampTz('recorded_at')->useCurrent();

            $table->foreign('snapshot_id', 'financial_record_snapshot_fk')
                ->references('snapshot_id')
                ->on('financial_snapshots')
                ->restrictOnDelete();
            $table->foreign('mapping_revision_id', 'financial_record_mapping_fk')
                ->references('mapping_revision_id')
                ->on('financial_source_mapping_revisions')
                ->restrictOnDelete();
            $table->unique(['snapshot_id', 'record_ordinal'], 'financial_record_ordinal_unique');
            $table->unique(['snapshot_id', 'source_identity_key'], 'financial_record_identity_unique');
            $table->index(['mapping_revision_id', 'snapshot_id'], 'financial_record_mapping_index');
        });

        Schema::create('financial_plan_revisions', static function (Blueprint $table): void {
            $table->id();
            $table->string('plan_revision_id', 64)->unique();
            $table->string('snapshot_id', 64)->unique();
            $table->string('mapping_revision_id', 64);
            $table->string('source_identity_key', 64);
            $table->string('content_fingerprint', 64);
            $table->timestampTz('as_of_at');
            $table->string('pricing_convention', 80);
            $table->string('basis_point_rounding', 40);
            $table->unsignedBigInteger('ticket_customer_price_cents');
            $table->unsignedBigInteger('ticket_quantity');
            $table->unsignedBigInteger('per_ticket_commission_cents');
            $table->unsignedBigInteger('fundraising_goal_cents');
            $table->unsignedInteger('university_allocation_basis_points');
            $table->unsignedInteger('donorbox_fee_basis_points');
            $table->unsignedBigInteger('planned_ticket_customer_charge_cents');
            $table->unsignedBigInteger('planned_commission_cents');
            $table->unsignedBigInteger('planned_ticket_proceeds_cents');
            $table->unsignedBigInteger('planned_university_fundraising_allocation_cents');
            $table->unsignedBigInteger('planned_donorbox_fee_cents');
            $table->unsignedBigInteger('planned_gross_income_cents');
            $table->unsignedBigInteger('planned_income_after_donorbox_fee_cents');
            $table->timestampTz('recorded_at')->useCurrent();

            $table->foreign('snapshot_id', 'financial_plan_snapshot_fk')
                ->references('snapshot_id')
                ->on('financial_snapshots')
                ->restrictOnDelete();
            $table->foreign('mapping_revision_id', 'financial_plan_mapping_fk')
                ->references('mapping_revision_id')
                ->on('financial_source_mapping_revisions')
                ->restrictOnDelete();
        });

        Schema::create('financial_reconciliation_receipts', static function (Blueprint $table): void {
            $table->id();
            $table->string('persistence_receipt_id', 64)->unique();
            $table->string('source_receipt_id', 64);
            $table->string('snapshot_id', 64);
            $table->string('append_classification', 24);
            $table->string('reconciliation_status', 24);
            $table->string('freshness', 16);
            $table->boolean('source_publishable');
            $table->boolean('policy_publishable');
            $table->boolean('promotion_eligible');
            $table->unsignedBigInteger('source_record_count');
            $table->unsignedBigInteger('imported_record_count');
            $table->unsignedBigInteger('excluded_count');
            $table->unsignedBigInteger('conflict_count');
            $table->unsignedBigInteger('discrepancy_count');
            $table->jsonb('source_totals_json');
            $table->jsonb('imported_totals_json');
            $table->jsonb('discrepancies_json');
            $table->timestampTz('source_as_of_at');
            $table->timestampTz('generated_at');
            $table->timestampTz('recorded_at')->useCurrent();

            $table->foreign('snapshot_id', 'financial_receipt_snapshot_fk')
                ->references('snapshot_id')
                ->on('financial_snapshots')
                ->restrictOnDelete();
            $table->unique('source_receipt_id', 'financial_receipt_source_id_unique');
            $table->index(
                ['snapshot_id', 'promotion_eligible', 'generated_at'],
                'financial_receipt_promotion_index',
            );
        });

        DB::unprepared(<<<'SQL'
ALTER TABLE financial_scopes
  ADD CONSTRAINT financial_scope_key_format CHECK (scope_key ~ '^[0-9a-f]{64}$'),
  ADD CONSTRAINT financial_scope_currency_format CHECK (currency ~ '^[A-Z]{3}$'),
  ADD CONSTRAINT financial_scope_university_nonempty CHECK (length(btrim(university_id)) > 0),
  ADD CONSTRAINT financial_scope_cycle_nonempty CHECK (length(btrim(cycle_id)) > 0),
  ADD CONSTRAINT financial_scope_timezone_nonempty CHECK (length(btrim(timezone)) > 0);

ALTER TABLE financial_source_mapping_revisions
  ADD CONSTRAINT financial_mapping_revision_id_format CHECK (mapping_revision_id ~ '^[0-9a-f]{64}$'),
  ADD CONSTRAINT financial_mapping_key_format CHECK (mapping_key ~ '^[0-9a-f]{64}$'),
  ADD CONSTRAINT financial_mapping_revision_positive CHECK (revision_number > 0),
  ADD CONSTRAINT financial_mapping_source_system CHECK (source_system IN ('google_sheet', 'spark', 'stripe', 'donorbox', 'hi_events')),
  ADD CONSTRAINT financial_mapping_object_kind CHECK (source_object_kind IN ('plan_record', 'ticket_event', 'donation_campaign')),
  ADD CONSTRAINT financial_mapping_disposition CHECK (disposition IN ('active', 'retired')),
  ADD CONSTRAINT financial_mapping_fingerprint_format CHECK (content_fingerprint ~ '^[0-9a-f]{64}$'),
  ADD CONSTRAINT financial_mapping_time_order CHECK (recorded_at >= effective_at),
  ADD CONSTRAINT financial_mapping_predecessor_shape CHECK (
    (revision_number = 1 AND supersedes_mapping_revision_id IS NULL)
    OR (revision_number > 1 AND supersedes_mapping_revision_id IS NOT NULL)
  );

ALTER TABLE financial_snapshots
  ADD CONSTRAINT financial_snapshot_id_format CHECK (snapshot_id ~ '^[0-9a-f]{64}$'),
  ADD CONSTRAINT financial_snapshot_stream_key_format CHECK (stream_key ~ '^[0-9a-f]{64}$'),
  ADD CONSTRAINT financial_snapshot_source_version_format CHECK (source_version_key ~ '^[0-9a-f]{64}$'),
  ADD CONSTRAINT financial_snapshot_kind CHECK (snapshot_kind IN ('planned_position', 'spark_ticket', 'stripe_settlement', 'donorbox')),
  ADD CONSTRAINT financial_snapshot_source_system CHECK (source_system IN ('google_sheet', 'spark', 'stripe', 'donorbox', 'hi_events')),
  ADD CONSTRAINT financial_snapshot_fingerprint_format CHECK (content_fingerprint ~ '^[0-9a-f]{64}$'),
  ADD CONSTRAINT financial_snapshot_reconciliation_status CHECK (reconciliation_status IN ('pass', 'fail', 'review_required')),
  ADD CONSTRAINT financial_snapshot_time_order CHECK (imported_at >= source_as_of_at),
  ADD CONSTRAINT financial_snapshot_publishability CHECK (NOT policy_publishable OR source_publishable);

ALTER TABLE financial_snapshot_records
  ADD CONSTRAINT financial_record_id_format CHECK (snapshot_record_id ~ '^[0-9a-f]{64}$'),
  ADD CONSTRAINT financial_record_identity_format CHECK (source_identity_key ~ '^[0-9a-f]{64}$'),
  ADD CONSTRAINT financial_record_fingerprint_format CHECK (content_fingerprint ~ '^[0-9a-f]{64}$'),
  ADD CONSTRAINT financial_record_currency_format CHECK (currency ~ '^[A-Z]{3}$'),
  ADD CONSTRAINT financial_record_quantity_positive CHECK (quantity > 0),
  ADD CONSTRAINT financial_record_time_order CHECK (source_updated_at >= source_occurred_at),
  ADD CONSTRAINT financial_record_refund_bound CHECK (refund_cents IS NULL OR gross_cents IS NULL OR refund_cents <= gross_cents),
  ADD CONSTRAINT financial_record_processor_refund_bound CHECK (processor_fee_refund_cents IS NULL OR processor_fee_cents IS NULL OR processor_fee_refund_cents <= processor_fee_cents),
  ADD CONSTRAINT financial_record_platform_refund_bound CHECK (platform_fee_refund_cents IS NULL OR platform_fee_cents IS NULL OR platform_fee_refund_cents <= platform_fee_cents),
  ADD CONSTRAINT financial_record_processor_provenance CHECK (processor_fee_provenance IS NULL OR processor_fee_provenance IN ('actual', 'estimated')),
  ADD CONSTRAINT financial_record_platform_provenance CHECK (platform_fee_provenance IS NULL OR platform_fee_provenance IN ('actual', 'estimated'));

ALTER TABLE financial_plan_revisions
  ADD CONSTRAINT financial_plan_id_format CHECK (plan_revision_id ~ '^[0-9a-f]{64}$'),
  ADD CONSTRAINT financial_plan_identity_format CHECK (source_identity_key ~ '^[0-9a-f]{64}$'),
  ADD CONSTRAINT financial_plan_fingerprint_format CHECK (content_fingerprint ~ '^[0-9a-f]{64}$'),
  ADD CONSTRAINT financial_plan_quantity_positive CHECK (ticket_quantity > 0),
  ADD CONSTRAINT financial_plan_allocation_bps CHECK (university_allocation_basis_points BETWEEN 0 AND 10000),
  ADD CONSTRAINT financial_plan_donorbox_bps CHECK (donorbox_fee_basis_points BETWEEN 0 AND 10000),
  ADD CONSTRAINT financial_plan_pricing_convention CHECK (pricing_convention = 'customer_price_less_commission'),
  ADD CONSTRAINT financial_plan_rounding CHECK (basis_point_rounding = 'half_up_to_cent');

ALTER TABLE financial_reconciliation_receipts
  ADD CONSTRAINT financial_receipt_id_format CHECK (persistence_receipt_id ~ '^[0-9a-f]{64}$'),
  ADD CONSTRAINT financial_receipt_source_id_format CHECK (source_receipt_id ~ '^[0-9a-f]{64}$'),
  ADD CONSTRAINT financial_receipt_append_classification CHECK (append_classification IN ('new_snapshot', 'new_revision', 'receipt_only', 'content_conflict', 'stale_snapshot')),
  ADD CONSTRAINT financial_receipt_status CHECK (reconciliation_status IN ('pass', 'fail', 'review_required')),
  ADD CONSTRAINT financial_receipt_freshness CHECK (freshness IN ('current', 'stale', 'unknown')),
  ADD CONSTRAINT financial_receipt_time_order CHECK (generated_at >= source_as_of_at),
  ADD CONSTRAINT financial_receipt_publishability CHECK (NOT policy_publishable OR source_publishable),
  ADD CONSTRAINT financial_receipt_promotion CHECK (
    NOT promotion_eligible OR (
      append_classification IN ('new_snapshot', 'new_revision', 'receipt_only')
      AND reconciliation_status = 'pass'
      AND freshness = 'current'
      AND source_publishable
      AND policy_publishable
    )
  );

CREATE FUNCTION kampy_jsonb_object_keys_allowed(value jsonb, allowed_keys text[])
RETURNS boolean
LANGUAGE sql
IMMUTABLE
STRICT
AS $$
  SELECT jsonb_typeof(value) = 'object'
    AND NOT EXISTS (
      SELECT 1
      FROM jsonb_object_keys(value) AS key
      WHERE NOT (key = ANY (allowed_keys))
    );
$$;

CREATE FUNCTION kampy_jsonb_safe_integer(value jsonb)
RETURNS boolean
LANGUAGE sql
IMMUTABLE
STRICT
AS $$
  SELECT CASE
    WHEN jsonb_typeof(value) <> 'number' OR value::text !~ '^-?[0-9]+$' THEN false
    ELSE (value::text)::numeric BETWEEN -9007199254740991 AND 9007199254740991
  END;
$$;

CREATE FUNCTION kampy_financial_discrepancies_safe(value jsonb)
RETURNS boolean
LANGUAGE sql
IMMUTABLE
STRICT
AS $$
  SELECT jsonb_typeof(value) = 'array'
    AND NOT EXISTS (
      SELECT 1
      FROM jsonb_array_elements(value) AS item
      WHERE jsonb_typeof(item) <> 'object'
        OR NOT kampy_jsonb_object_keys_allowed(item, ARRAY['field', 'sourceValue', 'importedValue', 'delta'])
        OR NOT (item ?& ARRAY['field', 'sourceValue', 'importedValue', 'delta'])
        OR item ->> 'field' <> ALL (ARRAY[
          'recordCount', 'quantity', 'grossCents', 'amountRefundedCents',
          'platformFeeCents', 'processorFeeCents', 'netCents',
          'customerChargeCents', 'kampProceedsCents', 'applicationFeeCents',
          'applicationFeeRefundCents', 'processorFeeRefundCents', 'refundCents',
          'paymentReversalCents', 'kampNetSettlementCents',
          'stripeProcessingFeeCents', 'connectedNetCents', 'disputeAmountCents',
          'disputeFeeCents', 'connectedSettlementAfterAdjustmentsCents'
        ])
        OR NOT kampy_jsonb_safe_integer(item -> 'sourceValue')
        OR NOT kampy_jsonb_safe_integer(item -> 'importedValue')
        OR NOT kampy_jsonb_safe_integer(item -> 'delta')
    );
$$;

CREATE FUNCTION kampy_financial_receipt_metrics_safe(value jsonb)
RETURNS boolean
LANGUAGE sql
IMMUTABLE
STRICT
AS $$
  SELECT jsonb_typeof(value) = 'object'
    AND kampy_jsonb_object_keys_allowed(
      value,
      ARRAY[
        'contentFingerprint', 'recordCount', 'quantity', 'currency',
        'includedProviderStatuses', 'provenance', 'grossCents',
        'amountRefundedCents', 'platformFeeCents', 'processorFeeCents', 'netCents',
        'customerChargeCents', 'kampProceedsCents', 'applicationFeeCents',
        'applicationFeeRefundCents', 'processorFeeRefundCents', 'refundCents',
        'paymentReversalCents', 'kampNetSettlementCents',
        'applicationFeeActualCents', 'applicationFeeEstimatedCents',
        'processorFeeActualCents', 'processorFeeEstimatedCents',
        'stripeProcessingFeeCents', 'connectedNetCents', 'disputeAmountCents',
        'disputeFeeCents', 'connectedSettlementAfterAdjustmentsCents'
      ]
    )
    AND NOT EXISTS (
      SELECT 1
      FROM jsonb_each(value) AS entry(metric_key, metric_value)
      WHERE CASE metric_key
        WHEN 'contentFingerprint' THEN
          jsonb_typeof(metric_value) <> 'string'
          OR metric_value #>> '{}' !~ '^[0-9a-f]{64}$'
        WHEN 'currency' THEN
          jsonb_typeof(metric_value) <> 'string'
          OR metric_value #>> '{}' !~ '^[A-Z]{3}$'
        WHEN 'provenance' THEN
          jsonb_typeof(metric_value) <> 'string'
          OR metric_value #>> '{}' <> ALL (ARRAY['dashboard_display', 'csv_export', 'api'])
        WHEN 'includedProviderStatuses' THEN
          CASE
            WHEN jsonb_typeof(metric_value) <> 'array' THEN true
            ELSE EXISTS (
              SELECT 1
              FROM jsonb_array_elements(metric_value) AS status(value)
              WHERE jsonb_typeof(status.value) <> 'string'
                OR status.value #>> '{}' <> ALL (ARRAY[
                  'Paid', 'Refunded', 'Waiting approval', 'Pending',
                  'Charge pending', 'Failed'
                ])
            )
          END
        ELSE metric_value <> 'null'::jsonb
          AND NOT kampy_jsonb_safe_integer(metric_value)
      END
    );
$$;

ALTER TABLE financial_snapshots
  ADD CONSTRAINT financial_snapshot_summary_safe CHECK (
    kampy_jsonb_object_keys_allowed(
      summary_json,
      CASE snapshot_kind
        WHEN 'planned_position' THEN ARRAY['plannedTicketProceedsCents', 'plannedFundraisingGoalCents', 'plannedGrossIncomeCents']
        WHEN 'spark_ticket' THEN ARRAY['eligibleTransactionCount', 'eligibilityDefinition', 'eligibilitySourceGrain', 'zeroPriceReviewCount', 'unpaidOrUnsettledCount']
        WHEN 'stripe_settlement' THEN ARRAY['semanticStatus', 'policyCompatible', 'connectedNetCents', 'connectedSettlementAfterAdjustmentsCents']
        WHEN 'donorbox' THEN ARRAY['controlledRecordCount', 'controlledGrossCents', 'incompleteRecordCount', 'contractStatus', 'grossControlStatus', 'netControlStatus', 'sourceWindowFromAt', 'sourceTimeZone']
      END
    )
  );

ALTER TABLE financial_reconciliation_receipts
  ADD CONSTRAINT financial_receipt_source_totals_safe CHECK (
    kampy_financial_receipt_metrics_safe(source_totals_json)
  ),
  ADD CONSTRAINT financial_receipt_imported_totals_safe CHECK (
    kampy_financial_receipt_metrics_safe(imported_totals_json)
  ),
  ADD CONSTRAINT financial_receipt_discrepancies_safe CHECK (kampy_financial_discrepancies_safe(discrepancies_json));

CREATE FUNCTION kampy_validate_financial_scope() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  event_row events%ROWTYPE;
  organizer_row organizers%ROWTYPE;
BEGIN
  SELECT * INTO event_row FROM events WHERE id = NEW.event_id;
  SELECT * INTO organizer_row FROM organizers WHERE id = NEW.organizer_id;

  IF NOT FOUND
    OR event_row.account_id <> NEW.account_id
    OR event_row.organizer_id IS NULL
    OR event_row.organizer_id <> NEW.organizer_id
    OR organizer_row.account_id <> NEW.account_id
    OR upper(event_row.currency) <> NEW.currency
    OR COALESCE(event_row.timezone, organizer_row.timezone) <> NEW.timezone THEN
    RAISE EXCEPTION 'financial scope does not match account, organizer, event, currency, and timezone hierarchy';
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER financial_scope_hierarchy
  BEFORE INSERT ON financial_scopes
  FOR EACH ROW EXECUTE FUNCTION kampy_validate_financial_scope();

CREATE FUNCTION kampy_validate_financial_mapping_revision() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  predecessor financial_source_mapping_revisions%ROWTYPE;
BEGIN
  IF NEW.revision_number = 1 THEN
    RETURN NEW;
  END IF;

  SELECT * INTO predecessor
  FROM financial_source_mapping_revisions
  WHERE mapping_revision_id = NEW.supersedes_mapping_revision_id;

  IF NOT FOUND
    OR predecessor.mapping_key <> NEW.mapping_key
    OR predecessor.revision_number <> NEW.revision_number - 1
    OR predecessor.effective_at > NEW.effective_at THEN
    RAISE EXCEPTION 'financial mapping revision must supersede the immediate same-key predecessor';
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER financial_source_mapping_revision_chain
  BEFORE INSERT ON financial_source_mapping_revisions
  FOR EACH ROW EXECUTE FUNCTION kampy_validate_financial_mapping_revision();

CREATE FUNCTION kampy_validate_financial_snapshot_record() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  snapshot financial_snapshots%ROWTYPE;
  mapping financial_source_mapping_revisions%ROWTYPE;
BEGIN
  SELECT * INTO snapshot FROM financial_snapshots WHERE snapshot_id = NEW.snapshot_id;
  SELECT * INTO mapping FROM financial_source_mapping_revisions WHERE mapping_revision_id = NEW.mapping_revision_id;

  IF snapshot.snapshot_kind = 'planned_position'
    OR NEW.source_updated_at > snapshot.source_as_of_at
    OR mapping.financial_scope_id <> snapshot.financial_scope_id
    OR mapping.source_system <> snapshot.source_system
    OR mapping.source_namespace <> snapshot.source_namespace
    OR mapping.disposition <> 'active'
    OR mapping.effective_at > snapshot.source_as_of_at THEN
    RAISE EXCEPTION 'financial snapshot record does not match snapshot and mapping scope';
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER financial_snapshot_record_scope
  BEFORE INSERT ON financial_snapshot_records
  FOR EACH ROW EXECUTE FUNCTION kampy_validate_financial_snapshot_record();

CREATE FUNCTION kampy_validate_financial_plan_revision() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  snapshot financial_snapshots%ROWTYPE;
  mapping financial_source_mapping_revisions%ROWTYPE;
BEGIN
  SELECT * INTO snapshot FROM financial_snapshots WHERE snapshot_id = NEW.snapshot_id;
  SELECT * INTO mapping FROM financial_source_mapping_revisions WHERE mapping_revision_id = NEW.mapping_revision_id;

  IF snapshot.snapshot_kind <> 'planned_position'
    OR snapshot.record_count <> 0
    OR NEW.as_of_at <> snapshot.source_as_of_at
    OR mapping.financial_scope_id <> snapshot.financial_scope_id
    OR mapping.source_system <> snapshot.source_system
    OR mapping.source_namespace <> snapshot.source_namespace
    OR mapping.disposition <> 'active'
    OR mapping.effective_at > snapshot.source_as_of_at THEN
    RAISE EXCEPTION 'financial plan revision does not match snapshot and mapping scope';
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER financial_plan_revision_scope
  BEFORE INSERT ON financial_plan_revisions
  FOR EACH ROW EXECUTE FUNCTION kampy_validate_financial_plan_revision();

CREATE FUNCTION kampy_validate_financial_reconciliation_receipt() RETURNS trigger
LANGUAGE plpgsql AS $$
DECLARE
  snapshot financial_snapshots%ROWTYPE;
  persisted_record_count bigint;
  persisted_plan_count bigint;
  controlled_record_count bigint;
  controlled_refund_count bigint;
  controlled_gross_cents bigint;
  controlled_refund_cents bigint;
BEGIN
  SELECT * INTO snapshot FROM financial_snapshots WHERE snapshot_id = NEW.snapshot_id;
  SELECT count(*) INTO persisted_record_count FROM financial_snapshot_records WHERE snapshot_id = NEW.snapshot_id;
  SELECT count(*) INTO persisted_plan_count FROM financial_plan_revisions WHERE snapshot_id = NEW.snapshot_id;

  IF NEW.source_as_of_at <> snapshot.source_as_of_at
    OR NEW.discrepancy_count <> jsonb_array_length(NEW.discrepancies_json) THEN
    RAISE EXCEPTION 'financial receipt does not match snapshot source or discrepancy count';
  END IF;

  IF snapshot.snapshot_kind = 'planned_position' THEN
    IF snapshot.record_count <> 0
      OR persisted_record_count <> 0
      OR persisted_plan_count <> 1
      OR NEW.imported_record_count <> 1 THEN
      RAISE EXCEPTION 'financial plan receipt does not match persisted plan revision';
    END IF;
  ELSE
    IF persisted_plan_count <> 0
      OR persisted_record_count <> snapshot.record_count
      OR NEW.imported_record_count <> persisted_record_count THEN
      RAISE EXCEPTION 'financial receipt does not match persisted snapshot records';
    END IF;
  END IF;

  IF snapshot.snapshot_kind = 'donorbox' AND NEW.source_publishable THEN
    IF jsonb_typeof(NEW.source_totals_json -> 'includedProviderStatuses') <> 'array'
      OR NOT (NEW.source_totals_json ? 'recordCount')
      OR NOT (NEW.source_totals_json ? 'grossCents')
      OR NOT (NEW.source_totals_json ? 'amountRefundedCents')
      OR NOT (NEW.imported_totals_json ? 'recordCount')
      OR NOT (NEW.imported_totals_json ? 'grossCents')
      OR NOT (NEW.imported_totals_json ? 'amountRefundedCents') THEN
      RAISE EXCEPTION 'source-controlled DonorBox receipt is missing gross controls';
    END IF;

    SELECT
      count(*),
      count(record.refund_cents),
      COALESCE(sum(record.gross_cents), 0),
      COALESCE(sum(record.refund_cents), 0)
    INTO
      controlled_record_count,
      controlled_refund_count,
      controlled_gross_cents,
      controlled_refund_cents
    FROM financial_snapshot_records AS record
    WHERE record.snapshot_id = NEW.snapshot_id
      AND record.provider_status IN (
        SELECT jsonb_array_elements_text(NEW.source_totals_json -> 'includedProviderStatuses')
      );

    IF controlled_refund_count <> controlled_record_count
      OR NEW.source_record_count <> controlled_record_count
      OR NEW.excluded_count <> persisted_record_count - controlled_record_count
      OR (NEW.source_totals_json ->> 'recordCount')::bigint <> controlled_record_count
      OR (NEW.imported_totals_json ->> 'recordCount')::bigint <> controlled_record_count
      OR (NEW.source_totals_json ->> 'grossCents')::bigint <> controlled_gross_cents
      OR (NEW.imported_totals_json ->> 'grossCents')::bigint <> controlled_gross_cents
      OR (NEW.source_totals_json ->> 'amountRefundedCents')::bigint <> controlled_refund_cents
      OR (NEW.imported_totals_json ->> 'amountRefundedCents')::bigint <> controlled_refund_cents THEN
      RAISE EXCEPTION 'source-controlled DonorBox gross receipt does not match persisted records';
    END IF;
  END IF;

  IF NEW.promotion_eligible AND (
    snapshot.reconciliation_status <> 'pass'
    OR NOT snapshot.source_publishable
    OR NOT snapshot.policy_publishable
    OR NEW.reconciliation_status <> 'pass'
    OR NEW.freshness <> 'current'
    OR NOT NEW.source_publishable
    OR NOT NEW.policy_publishable
  ) THEN
    RAISE EXCEPTION 'financial receipt cannot promote an unpublishable snapshot';
  END IF;
  RETURN NEW;
END;
$$;

CREATE TRIGGER financial_reconciliation_receipt_coherence
  BEFORE INSERT ON financial_reconciliation_receipts
  FOR EACH ROW EXECUTE FUNCTION kampy_validate_financial_reconciliation_receipt();

CREATE FUNCTION kampy_block_financial_history_mutation() RETURNS trigger
LANGUAGE plpgsql AS $$
BEGIN
  RAISE EXCEPTION '% is append only', TG_TABLE_NAME;
END;
$$;

CREATE TRIGGER financial_scopes_append_only
  BEFORE UPDATE OR DELETE ON financial_scopes
  FOR EACH ROW EXECUTE FUNCTION kampy_block_financial_history_mutation();
CREATE TRIGGER financial_source_mapping_revisions_append_only
  BEFORE UPDATE OR DELETE ON financial_source_mapping_revisions
  FOR EACH ROW EXECUTE FUNCTION kampy_block_financial_history_mutation();
CREATE TRIGGER financial_snapshots_append_only
  BEFORE UPDATE OR DELETE ON financial_snapshots
  FOR EACH ROW EXECUTE FUNCTION kampy_block_financial_history_mutation();
CREATE TRIGGER financial_snapshot_records_append_only
  BEFORE UPDATE OR DELETE ON financial_snapshot_records
  FOR EACH ROW EXECUTE FUNCTION kampy_block_financial_history_mutation();
CREATE TRIGGER financial_plan_revisions_append_only
  BEFORE UPDATE OR DELETE ON financial_plan_revisions
  FOR EACH ROW EXECUTE FUNCTION kampy_block_financial_history_mutation();
CREATE TRIGGER financial_reconciliation_receipts_append_only
  BEFORE UPDATE OR DELETE ON financial_reconciliation_receipts
  FOR EACH ROW EXECUTE FUNCTION kampy_block_financial_history_mutation();

CREATE VIEW financial_current_source_mappings AS
SELECT
  selected.mapping_revision_id,
  selected.mapping_key,
  selected.revision_number,
  selected.financial_scope_id,
  scope.scope_key,
  scope.account_id,
  scope.organizer_id,
  scope.event_id,
  scope.university_id,
  scope.cycle_id,
  scope.timezone,
  scope.currency,
  selected.source_system,
  selected.source_namespace,
  selected.source_object_kind,
  selected.source_object_id,
  selected.disposition,
  selected.content_fingerprint,
  selected.effective_at,
  selected.recorded_at
FROM (
  SELECT DISTINCT ON (mapping_key) *
  FROM financial_source_mapping_revisions
  ORDER BY mapping_key, revision_number DESC, id DESC
) AS selected
JOIN financial_scopes AS scope ON scope.id = selected.financial_scope_id
WHERE selected.disposition = 'active';

CREATE VIEW financial_latest_promotable_snapshots AS
SELECT selected.*
FROM (
  SELECT DISTINCT ON (snapshot.stream_key)
    snapshot.snapshot_id,
    snapshot.stream_key,
    snapshot.snapshot_kind,
    snapshot.source_system,
    snapshot.source_namespace,
    snapshot.adapter_version,
    snapshot.financial_scope_id,
    scope.scope_key,
    scope.account_id,
    scope.organizer_id,
    scope.event_id,
    scope.university_id,
    scope.cycle_id,
    scope.timezone,
    scope.currency,
    snapshot.source_as_of_at,
    snapshot.imported_at,
    snapshot.policy_version,
    snapshot.content_fingerprint,
    snapshot.summary_json,
    receipt.persistence_receipt_id,
    receipt.generated_at AS receipt_generated_at
  FROM financial_snapshots AS snapshot
  JOIN financial_scopes AS scope ON scope.id = snapshot.financial_scope_id
  JOIN financial_reconciliation_receipts AS receipt ON receipt.snapshot_id = snapshot.snapshot_id
  WHERE receipt.promotion_eligible
    AND snapshot.reconciliation_status = 'pass'
    AND snapshot.source_publishable
    AND snapshot.policy_publishable
    AND receipt.reconciliation_status = 'pass'
    AND receipt.freshness = 'current'
    AND receipt.source_publishable
    AND receipt.policy_publishable
    AND receipt.source_as_of_at = snapshot.source_as_of_at
  ORDER BY
    snapshot.stream_key,
    snapshot.source_as_of_at DESC,
    receipt.generated_at DESC,
    receipt.persistence_receipt_id DESC,
    snapshot.snapshot_id DESC,
    snapshot.id DESC
) AS selected;

CREATE VIEW financial_latest_source_controlled_snapshots AS
SELECT selected.*
FROM (
  SELECT DISTINCT ON (snapshot.stream_key)
    snapshot.snapshot_id,
    snapshot.stream_key,
    snapshot.snapshot_kind,
    snapshot.source_system,
    snapshot.source_namespace,
    snapshot.adapter_version,
    snapshot.financial_scope_id,
    scope.scope_key,
    scope.account_id,
    scope.organizer_id,
    scope.event_id,
    scope.university_id,
    scope.cycle_id,
    scope.timezone,
    scope.currency,
    snapshot.source_as_of_at,
    snapshot.imported_at,
    snapshot.policy_version,
    snapshot.content_fingerprint,
    snapshot.summary_json,
    receipt.persistence_receipt_id,
    receipt.generated_at AS receipt_generated_at
  FROM financial_snapshots AS snapshot
  JOIN financial_scopes AS scope ON scope.id = snapshot.financial_scope_id
  JOIN financial_reconciliation_receipts AS receipt ON receipt.snapshot_id = snapshot.snapshot_id
  WHERE snapshot.reconciliation_status IN ('pass', 'review_required')
    AND receipt.append_classification IN ('new_snapshot', 'new_revision', 'receipt_only')
    AND snapshot.source_publishable
    AND receipt.reconciliation_status IN ('pass', 'review_required')
    AND receipt.freshness = 'current'
    AND receipt.source_publishable
    AND receipt.source_as_of_at = snapshot.source_as_of_at
  ORDER BY
    snapshot.stream_key,
    snapshot.source_as_of_at DESC,
    receipt.generated_at DESC,
    receipt.persistence_receipt_id DESC,
    snapshot.snapshot_id DESC,
    snapshot.id DESC
) AS selected;
SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
DROP VIEW IF EXISTS financial_latest_source_controlled_snapshots;
DROP VIEW IF EXISTS financial_latest_promotable_snapshots;
DROP VIEW IF EXISTS financial_current_source_mappings;
SQL);

        Schema::dropIfExists('financial_reconciliation_receipts');
        Schema::dropIfExists('financial_plan_revisions');
        Schema::dropIfExists('financial_snapshot_records');
        Schema::dropIfExists('financial_snapshots');
        Schema::dropIfExists('financial_source_mapping_revisions');
        Schema::dropIfExists('financial_scopes');

        DB::unprepared(<<<'SQL'
DROP FUNCTION IF EXISTS kampy_block_financial_history_mutation();
DROP FUNCTION IF EXISTS kampy_validate_financial_reconciliation_receipt();
DROP FUNCTION IF EXISTS kampy_validate_financial_plan_revision();
DROP FUNCTION IF EXISTS kampy_validate_financial_snapshot_record();
DROP FUNCTION IF EXISTS kampy_validate_financial_mapping_revision();
DROP FUNCTION IF EXISTS kampy_validate_financial_scope();
DROP FUNCTION IF EXISTS kampy_financial_discrepancies_safe(jsonb);
DROP FUNCTION IF EXISTS kampy_financial_receipt_metrics_safe(jsonb);
DROP FUNCTION IF EXISTS kampy_jsonb_safe_integer(jsonb);
DROP FUNCTION IF EXISTS kampy_jsonb_object_keys_allowed(jsonb, text[]);
SQL);
    }
};
