<?php

declare(strict_types=1);

namespace HiEvents\Http\Actions\Registration;

use HiEvents\Http\Actions\BaseAction;
use HiEvents\Http\Request\Registration\GvsuRegistrationCurrentStateRequest;
use HiEvents\Http\ResponseCodes;
use HiEvents\Services\Domain\Registration\GvsuRegistrationBridgeConfig;
use HiEvents\Services\Domain\Registration\GvsuRegistrationBridgeCredentialVerifier;
use HiEvents\Services\Domain\Registration\GvsuRegistrationBridgeService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class GvsuRegistrationCurrentStateAction extends BaseAction
{
    public function __construct(
        private readonly GvsuRegistrationBridgeCredentialVerifier $credentialVerifier,
        private readonly GvsuRegistrationBridgeService $bridge,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (! GvsuRegistrationBridgeConfig::enabled() || ! $this->credentialVerifier->accepts($this->bearer($request->header('Authorization')))) {
            return $this->errorResponse(__('Not found.'), ResponseCodes::HTTP_NOT_FOUND);
        }

        $validated = $request->validate((new GvsuRegistrationCurrentStateRequest)->rules());

        return $this->jsonResponse($this->bridge->currentState($validated));
    }

    private function bearer(?string $authorization): ?string
    {
        if (! is_string($authorization) || ! str_starts_with($authorization, 'Bearer ')) {
            return null;
        }

        return substr($authorization, 7);
    }
}
