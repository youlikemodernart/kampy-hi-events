<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Financial;

use HiEvents\Http\Request\BaseRequest;

class GetFinancialReportRequest extends BaseRequest
{
    public function rules(): array
    {
        $scopeIdentifier = ['required', 'string', 'max:128', 'regex:/^[A-Za-z0-9][A-Za-z0-9._:-]*$/'];

        return [
            'university_id' => $scopeIdentifier,
            'cycle_id' => $scopeIdentifier,
            'cutoff_at' => ['required', 'date_format:Y-m-d\TH:i:sP'],
            'include_reconciliation' => ['sometimes', 'boolean'],
        ];
    }
}
