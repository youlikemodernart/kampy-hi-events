<?php

namespace HiEvents\Http\Actions\Registration;

use Illuminate\Http\Request;

final class RespondentConfirmationRequestGate
{
    public static function allows(Request $request): bool
    {
        $origin = config('respondent-confirmation.origin');

        return config('respondent-confirmation.enabled') === true
            && is_string($origin) && str_starts_with($origin, 'https://')
            && $request->header('Origin') === $origin
            && $request->header('X-Kamp-Respondent-Intent') === 'confirm'
            && in_array($request->header('Sec-Fetch-Site'), [null, 'same-origin'], true)
            && $request->isJson() && $request->getMethod() === 'POST'
            && strlen($request->getContent()) <= 65536
            && ! $request->query->has('verification_code');
    }
}
