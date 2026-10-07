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

    public function invitationHandoff(int $orderId, array $assignmentIds): array
    {
        $response = $this->request('/api/internal/gvsu-registration/invitation-handoff', [
            'operation' => 'completion-invitation-handoff-v1', 'event_id' => '7', 'order_id' => (string) $orderId, 'assignment_ids' => $assignmentIds,
        ]);
        if (! $response->successful() || ! is_array($response->json('links'))) {
            throw new GvsuRegistrationBridgeUnknownException(__('Waivers are not ready yet.'));
        }
        foreach ($response->json('links') as $link) {
            $url = $link['url'] ?? '';
            if (! is_string($url) || parse_url($url, PHP_URL_SCHEME) !== 'https' || parse_url($url, PHP_URL_HOST) !== config('services.gvsu_registration_bridge.portal_host')
                || parse_url($url, PHP_URL_USER) !== null || parse_url($url, PHP_URL_PASS) !== null || parse_url($url, PHP_URL_QUERY) !== null
                || ! in_array(parse_url($url, PHP_URL_PORT), [null, 443], true)
                || ! preg_match('/\A[A-Za-z0-9_-]{43}\z/', (string) parse_url($url, PHP_URL_FRAGMENT))
                || parse_url($url, PHP_URL_PATH) !== '/r/invitation/'.substr(hash('sha256', (string) parse_url($url, PHP_URL_FRAGMENT)), 0, 32)) {
                throw new GvsuRegistrationBridgeUnknownException(__('Waiver link unavailable.'));
            }
        }

        return $response->json('links');
    }

    public function historicalAbsence(array $identity): bool
    {
        // Exact authenticated metadata read, independent of provisioning/delivery activation.
        // This read is safe to repeat. One bounded retry absorbs a cold start or lost response
        // without retrying any provisioning, assignment, handoff, or delivery write.
        for ($attempt = 0; $attempt < 2; $attempt++) {
            try {
                $response = $this->request('/api/internal/gvsu-registration/historical-preflight', $identity);
            } catch (GvsuRegistrationBridgeUnknownException $exception) {
                if ($attempt === 0) {
                    usleep(200000);
                    continue;
                }

                throw $exception;
            }
            if ($response->serverError() && $attempt === 0) {
                usleep(200000);
                continue;
            }

            return $response->successful() && $response->json('classification') === 'absent'
                && $response->json('identity_digest') === hash('sha256', \HiEvents\Services\Domain\Registration\HistoricalReceiptValidator::canonical($identity));
        }

        return false;
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
