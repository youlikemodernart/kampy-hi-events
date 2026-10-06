<?php

namespace HiEvents\Services\Infrastructure\Mail;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

/** Symfony's Postmark transport otherwise sends tracking controls as inert custom headers. */
final class CompletionInvitationPostmarkClient implements HttpClientInterface
{
    public const HEADER = 'X-Kamp-Completion-Invitation';

    public function __construct(private readonly HttpClientInterface $client) {}

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        $headers = $options['json']['Headers'] ?? [];
        $invitation = false;
        foreach ($headers as $header) {
            if (strcasecmp($header['Name'], self::HEADER) === 0 && $header['Value'] === '1') {
                $invitation = true;
            }
        }
        if ($invitation && $method === 'POST' && $url === 'https://api.postmarkapp.com/email') {
            $options['json']['TrackOpens'] = false;
            $options['json']['TrackLinks'] = 'None';
            $options['json']['Headers'] = array_values(array_filter($headers, fn ($header) => ! in_array(strtolower($header['Name']), [strtolower(self::HEADER), 'x-pm-trackopens', 'x-pm-tracklinks'], true)));
        }

        return $this->client->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        return $this->client->stream($responses, $timeout);
    }

    public function withOptions(array $options): static
    {
        return new self($this->client->withOptions($options));
    }
}
