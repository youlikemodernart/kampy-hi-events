<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Registration;

use HiEvents\Exceptions\GvsuRegistrationBridgeUnknownException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Factory as HttpFactory;

class GvsuRegistrationBridgePortalClient
{
    public function __construct(private readonly HttpFactory $http) {}

    public function provision(array $batch): void
    {
        $response = $this->request(GvsuRegistrationBridgeConfig::PROVISION_PATH, $batch);
        if (! $response->successful() || $response->json('classification') !== 'accepted') {
            throw new GvsuRegistrationBridgeUnknownException(__('GVSU registration bridge delivery is unknown.'));
        }
    }

    public function clearance(array $candidate): bool
    {
        $response = $this->request(GvsuRegistrationBridgeConfig::CLEARANCE_PATH, $candidate);

        return $response->successful() && $response->json('eligible') === true;
    }

    private function request(string $path, array $body)
    {
        try {
            return $this->http
                ->acceptJson()
                ->asJson()
                ->timeout(5)
                ->connectTimeout(2)
                ->withOptions(['allow_redirects' => false])
                ->withToken(GvsuRegistrationBridgeConfig::outgoingBearer())
                ->post(GvsuRegistrationBridgeConfig::portalUrl($path), $body);
        } catch (ConnectionException) {
            throw new GvsuRegistrationBridgeUnknownException(__('GVSU registration bridge delivery is unknown.'));
        }
    }
}
