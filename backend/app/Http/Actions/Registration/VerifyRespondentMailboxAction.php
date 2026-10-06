<?php

namespace HiEvents\Http\Actions\Registration;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Services\Domain\Registration\RespondentConfirmationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

final class VerifyRespondentMailboxAction extends BaseAction
{
    public function __construct(private readonly RespondentConfirmationService $service) {}

    public function __invoke(Request $request, string $orderShortId): JsonResponse
    {
        if (! RespondentConfirmationRequestGate::allows($request)) {
            return $this->jsonResponse([], 404);
        }
        $siblings = null;
        try {
            if (is_string($request->input('verification_code'))) {
                $siblings = $this->service->verify($orderShortId, $request->input('verification_code'));
            }
        } catch (Throwable) {
            // Never retain verification codes, addresses or evidence in exception logs.
        }

        return $this->jsonResponse($siblings === null ? ['status' => 'not_verified'] : ['status' => 'verified', 'siblings' => $siblings], $siblings === null ? 409 : 200)
            ->header('Cache-Control', 'no-store')->header('Referrer-Policy', 'no-referrer');
    }
}
