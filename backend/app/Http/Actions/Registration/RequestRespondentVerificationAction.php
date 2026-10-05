<?php

namespace HiEvents\Http\Actions\Registration;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Domain\Registration\RespondentConfirmationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class RequestRespondentVerificationAction extends BaseAction
{
    public function __construct(private readonly RespondentConfirmationService $service) {}

    public function __invoke(Request $request, string $orderShortId): JsonResponse
    {
        if (! RespondentConfirmationRequestGate::allows($request)) {
            return $this->jsonResponse([], 404);
        }
        try {
            $this->service->request($orderShortId);
        } catch (Throwable) {
            // Identical response for missing orders, throttling, unavailable configuration and uncertain mail handoff.
        }

        return $this->jsonResponse(['message' => __('If this order is eligible, a verification code will be sent to the purchase email address.')], 202)->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
