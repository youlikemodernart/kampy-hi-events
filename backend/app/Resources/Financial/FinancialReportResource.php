<?php

declare(strict_types=1);

namespace HiEvents\Resources\Financial;

use HiEvents\Resources\BaseResource;
use HiEvents\Services\Application\Handlers\Financial\DTO\FinancialReportResponseDTO;

/**
 * @mixin FinancialReportResponseDTO
 */
class FinancialReportResource extends BaseResource
{
    public function toArray($request): array
    {
        return [
            'scope' => $this->scope,
            'cutoff_at' => $this->cutoffAt,
            'generated_at' => $this->generatedAt,
            'reporting_timezone' => $this->reportingTimezone,
            'financial_policy' => $this->financialPolicy,
            'plan' => $this->plan,
            'tickets' => $this->tickets,
            'donations' => $this->donations,
            'variances' => $this->variances,
            'current_position' => $this->currentPosition,
            'source_evidence' => $this->sourceEvidence,
            'publishable' => $this->publishable,
            'reconciliation' => $this->when(
                $this->reconciliation !== null,
                $this->reconciliation,
            ),
        ];
    }
}
