<?php

declare(strict_types=1);

namespace HiEvents\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancialPlanRevision extends BaseModel
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
            'as_of_at' => 'datetime',
            'ticket_customer_price_cents' => 'integer',
            'ticket_quantity' => 'integer',
            'per_ticket_commission_cents' => 'integer',
            'fundraising_goal_cents' => 'integer',
            'university_allocation_basis_points' => 'integer',
            'donorbox_fee_basis_points' => 'integer',
            'planned_ticket_customer_charge_cents' => 'integer',
            'planned_commission_cents' => 'integer',
            'planned_ticket_proceeds_cents' => 'integer',
            'planned_university_fundraising_allocation_cents' => 'integer',
            'planned_donorbox_fee_cents' => 'integer',
            'planned_gross_income_cents' => 'integer',
            'planned_income_after_donorbox_fee_cents' => 'integer',
            'recorded_at' => 'datetime',
        ];
    }

    protected function getFillableFields(): array
    {
        return [
            'plan_revision_id',
            'snapshot_id',
            'mapping_revision_id',
            'source_identity_key',
            'content_fingerprint',
            'as_of_at',
            'pricing_convention',
            'basis_point_rounding',
            'ticket_customer_price_cents',
            'ticket_quantity',
            'per_ticket_commission_cents',
            'fundraising_goal_cents',
            'university_allocation_basis_points',
            'donorbox_fee_basis_points',
            'planned_ticket_customer_charge_cents',
            'planned_commission_cents',
            'planned_ticket_proceeds_cents',
            'planned_university_fundraising_allocation_cents',
            'planned_donorbox_fee_cents',
            'planned_gross_income_cents',
            'planned_income_after_donorbox_fee_cents',
            'recorded_at',
        ];
    }

    protected function getTimestampsEnabled(): bool
    {
        return false;
    }
}
