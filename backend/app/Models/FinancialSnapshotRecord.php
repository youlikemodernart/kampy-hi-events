<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialSnapshotRecord extends BaseModel
{
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(FinancialSnapshot::class, 'snapshot_id', 'snapshot_id');
    }

    public function mappingRevision(): BelongsTo
    {
        return $this->belongsTo(
            FinancialSourceMappingRevision::class,
            'mapping_revision_id',
            'mapping_revision_id',
        );
    }

    protected function getCastMap(): array
    {
        return [
            'record_ordinal' => 'integer',
            'quantity' => 'integer',
            'gross_cents' => 'integer',
            'processor_fee_cents' => 'integer',
            'processor_fee_refund_cents' => 'integer',
            'platform_fee_cents' => 'integer',
            'platform_fee_refund_cents' => 'integer',
            'refund_cents' => 'integer',
            'payment_reversal_cents' => 'integer',
            'dispute_fee_cents' => 'integer',
            'provider_net_cents' => 'integer',
            'net_settlement_cents' => 'integer',
            'source_occurred_at' => 'datetime',
            'source_updated_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    protected function getFillableFields(): array
    {
        return [
            'snapshot_record_id',
            'snapshot_id',
            'record_ordinal',
            'mapping_revision_id',
            'source_identity_key',
            'content_fingerprint',
            'provider_status',
            'financial_status',
            'recognition_disposition',
            'source_completeness_status',
            'source_method',
            'currency',
            'quantity',
            'gross_cents',
            'processor_fee_cents',
            'processor_fee_refund_cents',
            'processor_fee_provenance',
            'platform_fee_cents',
            'platform_fee_refund_cents',
            'platform_fee_provenance',
            'refund_cents',
            'payment_reversal_cents',
            'dispute_fee_cents',
            'provider_net_cents',
            'net_settlement_cents',
            'settlement_semantic_status',
            'source_occurred_at',
            'source_updated_at',
            'recorded_at',
        ];
    }

    protected function getTimestampsEnabled(): bool
    {
        return false;
    }
}
