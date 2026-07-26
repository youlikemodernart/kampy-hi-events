<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FinancialSnapshot extends BaseModel
{
    public function financialScope(): BelongsTo
    {
        return $this->belongsTo(FinancialScope::class);
    }

    public function records(): HasMany
    {
        return $this->hasMany(FinancialSnapshotRecord::class, 'snapshot_id', 'snapshot_id');
    }

    public function planRevision(): HasOne
    {
        return $this->hasOne(FinancialPlanRevision::class, 'snapshot_id', 'snapshot_id');
    }

    public function receipts(): HasMany
    {
        return $this->hasMany(FinancialReconciliationReceipt::class, 'snapshot_id', 'snapshot_id');
    }

    protected function getCastMap(): array
    {
        return [
            'financial_scope_id' => 'integer',
            'source_as_of_at' => 'datetime',
            'imported_at' => 'datetime',
            'source_publishable' => 'boolean',
            'policy_publishable' => 'boolean',
            'record_count' => 'integer',
            'summary_json' => 'array',
            'recorded_at' => 'datetime',
        ];
    }

    protected function getFillableFields(): array
    {
        return [
            'snapshot_id',
            'stream_key',
            'source_version_key',
            'financial_scope_id',
            'snapshot_kind',
            'source_system',
            'source_namespace',
            'adapter_version',
            'source_as_of_at',
            'imported_at',
            'policy_version',
            'content_fingerprint',
            'reconciliation_status',
            'source_publishable',
            'policy_publishable',
            'record_count',
            'summary_json',
            'recorded_at',
        ];
    }

    protected function getTimestampsEnabled(): bool
    {
        return false;
    }
}
