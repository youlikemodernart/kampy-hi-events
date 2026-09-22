<?php

declare(strict_types=1);

namespace HiEvents\Http\Request\Registration;

use HiEvents\Http\Request\BaseRequest;

class GvsuRegistrationCurrentStateRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'operation' => 'required|string|in:gvsu-registration-current-state-v1',
            'event_id' => 'required|string|in:7',
            'order_id' => 'required|string|max:32',
            'attendee_id' => 'required|string|max:32',
            'public_ticket_id' => 'required|string|max:128',
            'respondent_id' => 'required|string|max:80',
            'assignment_id' => 'required|string|max:80',
            'attendee_display_name' => ['required', 'string', 'min:1', 'max:200', 'regex:/\\A[^\\x00-\\x1F\\x7F]+\\z/'],
            'respondent_identity_digest_sha256' => ['required', 'string', 'regex:/\\A[0-9a-f]{64}\\z/'],
            'designated_delivery_email' => 'required|string|email:rfc|max:255',
        ];
    }
}
