<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Financial;

use HiEvents\Http\Request\BaseRequest;

class ExportFinancialReportRequest extends BaseRequest
{
    public function rules(): array
    {
        $scopeIdentifier = ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'];

        return [
            'university_id' => $scopeIdentifier,
            'cycle_id' => $scopeIdentifier,
            'cutoff_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'include_reconciliation' => ['prohibited'],
            'scope_key' => ['prohibited'],
            'organizer_id' => ['prohibited'],
            'plan_source_namespace' => ['prohibited'],
            'ticket_source_namespace' => ['prohibited'],
            'settlement_source_namespace' => ['prohibited'],
            'donation_source_namespace' => ['prohibited'],
        ];
    }
}
