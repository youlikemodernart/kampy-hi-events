<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialReconciliationReceipt extends BaseModel
{
    public function snapshot(): BelongsTo
    {
        return $this->belongsTo(FinancialSnapshot::class, 'snapshot_id', 'snapshot_id');
    }

    protected function getCastMap(): array
    {
        return [
            'source_publishable' => 'boolean',
            'policy_publishable' => 'boolean',
            'promotion_eligible' => 'boolean',
            'source_record_count' => 'integer',
            'imported_record_count' => 'integer',
            'excluded_count' => 'integer',
            'conflict_count' => 'integer',
            'discrepancy_count' => 'integer',
            'source_totals_json' => 'array',
            'imported_totals_json' => 'array',
            'discrepancies_json' => 'array',
            'source_as_of_at' => 'datetime',
            'generated_at' => 'datetime',
            'recorded_at' => 'datetime',
        ];
    }

    protected function getFillableFields(): array
    {
        return [
            'persistence_receipt_id',
            'source_receipt_id',
            'snapshot_id',
            'append_classification',
            'reconciliation_status',
            'freshness',
            'source_publishable',
            'policy_publishable',
            'promotion_eligible',
            'source_record_count',
            'imported_record_count',
            'excluded_count',
            'conflict_count',
            'discrepancy_count',
            'source_totals_json',
            'imported_totals_json',
            'discrepancies_json',
            'source_as_of_at',
            'generated_at',
            'recorded_at',
        ];
    }

    protected function getTimestampsEnabled(): bool
    {
        return false;
    }
}
