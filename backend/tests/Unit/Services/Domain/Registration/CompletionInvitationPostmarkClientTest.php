<?php

namespace Tests\Unit\Services\Domain\Registration;

use HiEvents\Mail\RespondentConfirmationChallenge;
use HiEvents\Services\Infrastructure\Mail\CompletionInvitationPostmarkClient;
use Illuminate\Support\Facades\Mail;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class CompletionInvitationPostmarkClientTest extends TestCase
{
    public function test_actual_postmark_serialization_disables_tracking_only_for_invitation(): void
    {
        $payloads = [];
        $client = new MockHttpClient(function ($method, $url, $options) use (&$payloads) {
            self::assertSame('POST', $method);
            self::assertSame('https://api.postmarkapp.com/email', $url);
            $payloads[] = json_decode($options['body'], true, 512, JSON_THROW_ON_ERROR);

            return new MockResponse(json_encode(['ErrorCode' => 0, 'MessageID' => 'synthetic-message', 'Message' => 'OK']), ['http_code' => 200]);
        });
        $transport = new PostmarkApiTransport('synthetic-not-a-credential', new CompletionInvitationPostmarkClient($client));
        $transport->setMessageStream('outbound');
        $mailer = Mail::mailer('array');
        $mailer->setSymfonyTransport($transport);
        $mailer->to('synthetic@example.test')->send(new RespondentConfirmationChallenge(str_repeat('a', 64), 'https://tickets.example.test/api/registration/invitation/synthetic#'.str_repeat('a', 64)));
        self::assertFalse($payloads[0]['TrackOpens']);
        self::assertSame('None', $payloads[0]['TrackLinks']);
        self::assertSame('outbound', $payloads[0]['MessageStream']);
        foreach ($payloads[0]['Headers'] as $header) {
            self::assertNotContains(strtolower($header['Name']), ['x-kamp-completion-invitation', 'x-pm-trackopens', 'x-pm-tracklinks']);
        }
        $mailer->to('synthetic@example.test')->send(new RespondentConfirmationChallenge(str_repeat('a', 64)));
        self::assertArrayNotHasKey('TrackOpens', $payloads[1]);
        self::assertArrayNotHasKey('TrackLinks', $payloads[1]);
        $ordinary = (new Email)->from('sender@example.test')->to('recipient@example.test')->subject('Ordinary')->text('Unchanged');
        $ordinary->getHeaders()->addTextHeader('X-Ordinary', 'preserved');
        $transport->send($ordinary);
        self::assertArrayNotHasKey('TrackOpens', $payloads[2]);
        self::assertArrayNotHasKey('TrackLinks', $payloads[2]);
        self::assertContains(['Name' => 'X-Ordinary', 'Value' => 'preserved'], $payloads[2]['Headers']);
        self::assertCount(3, $payloads);
    }

    public function test_registered_postmark_transport_keeps_installed_transport_and_message_stream(): void
    {
        config()->set('mail.mailers.postmark.token', 'synthetic-not-a-credential');
        config()->set('mail.mailers.postmark.message_stream_id', 'synthetic-stream');
        $transport = Mail::mailer('postmark')->getSymfonyTransport();
        self::assertInstanceOf(PostmarkApiTransport::class, $transport);
        self::assertStringContainsString('message_stream=synthetic-stream', (string) $transport);
        $property = new \ReflectionProperty(\Symfony\Component\Mailer\Transport\AbstractHttpTransport::class, 'client');
        self::assertInstanceOf(CompletionInvitationPostmarkClient::class, $property->getValue($transport));
    }
}
