<?php

namespace HiEvents\Http\Actions\Registration;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Domain\Registration\RespondentConfirmationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class ConfirmRespondentsAction extends BaseAction
{
    public function __construct(private readonly RespondentConfirmationService $service) {}

    public function __invoke(Request $request, string $orderShortId): JsonResponse
    {
        if (! RespondentConfirmationRequestGate::allows($request)) {
            return $this->jsonResponse([], 404);
        }
        $accepted = false;
        try {
            if (is_string($request->input('verification_code')) && is_array($request->input('respondents')) && $request->input('acknowledged') === true) {
                $accepted = $this->service->confirm($orderShortId, $request->input('verification_code'), $request->input('respondents'));
            }
        } catch (Throwable) {
            // Never log challenge, identity or request payloads; database rollback preserves single-use semantics.
        }

        return $this->jsonResponse(['status' => $accepted ? 'confirmed' : 'not_confirmed'], $accepted ? 200 : 409)
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
