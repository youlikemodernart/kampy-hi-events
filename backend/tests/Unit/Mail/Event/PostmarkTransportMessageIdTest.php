<?php

namespace Tests\Unit\Mail\Event;

use ErrorException;
use HiEvents\Mail\Event\EventReminder;
use HiEvents\Services\Domain\Email\DTO\UniversityEmailThemeDTO;
use HiEvents\Services\Domain\Message\DTO\EventReminderContext;
use Illuminate\Mail\Mailer;
use Illuminate\Support\Facades\Queue;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Mailer\Bridge\Postmark\Transport\PostmarkApiTransport;
use Symfony\Component\Mime\Email;
use Tests\TestCase;

class PostmarkTransportMessageIdTest extends TestCase
{
    public function test_successful_postmark_response_replaces_the_generated_mime_id(): void
    {
        $transport = $this->transportWithResponse([
            'ErrorCode' => 0,
            'Message' => 'OK',
            'MessageID' => 'postmark-provider-message-id',
        ]);
        $email = $this->emailWithMimeId('generated-mime-id@example.test');

        $sent = $transport->send($email);

        self::assertSame('postmark-provider-message-id', $sent->getMessageId());
        self::assertNotSame('generated-mime-id@example.test', $sent->getMessageId());
    }

    public function test_blank_postmark_message_id_stays_blank_instead_of_falling_back_to_mime_id(): void
    {
        $transport = $this->transportWithResponse([
            'ErrorCode' => 0,
            'Message' => 'OK',
            'MessageID' => '',
        ]);
        $email = $this->emailWithMimeId('generated-mime-id@example.test');

        $sent = $transport->send($email);

        self::assertSame('', $sent->getMessageId());
        self::assertNotSame('generated-mime-id@example.test', $sent->getMessageId());
    }

    public function test_laravel_send_now_bypasses_the_mailable_queue_and_returns_the_postmark_response_id(): void
    {
        $client = new MockHttpClient(new MockResponse(json_encode([
            'ErrorCode' => 0,
            'Message' => 'OK',
            'MessageID' => 'postmark-send-now-id',
        ], JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => ['content-type: application/json'],
        ]));
        $mailer = app(Mailer::class);
        $mailer->setSymfonyTransport(new PostmarkApiTransport('fixture-server-token', $client));
        Queue::fake();
        $context = new EventReminderContext(
            'Fixture Event',
            'https://fixture.test/event',
            'January 1, 2030 1:00 PM',
            'UTC',
            null,
            'support@fixture.test',
            'sender@fixture.test',
            'reply@fixture.test',
            '1 Fixture Way',
            'https://fixture.test/preferences',
            'Fixture',
            new UniversityEmailThemeDTO('#111111', '#222222', '#ffffff', '#ffffff', '#eeeeee'),
        );

        $sent = $mailer->to('recipient@example.test')->sendNow(new EventReminder($context));

        self::assertSame('postmark-send-now-id', $sent?->getMessageId());
        self::assertSame(1, $client->getRequestsCount());
        Queue::assertNothingPushed();
    }

    public function test_missing_postmark_message_id_fails_instead_of_falling_back_to_mime_id(): void
    {
        $transport = $this->transportWithResponse([
            'ErrorCode' => 0,
            'Message' => 'OK',
        ]);
        $email = $this->emailWithMimeId('generated-mime-id@example.test');
        set_error_handler(static function (int $severity, string $message): never {
            throw new ErrorException($message, 0, $severity);
        });

        try {
            $transport->send($email);
            self::fail('A Postmark response without MessageID must not be accepted.');
        } catch (\Throwable $exception) {
            self::assertStringContainsString('MessageID', $exception->getMessage());
        } finally {
            restore_error_handler();
        }
    }

    private function transportWithResponse(array $payload): PostmarkApiTransport
    {
        $response = new MockResponse(json_encode($payload, JSON_THROW_ON_ERROR), [
            'http_code' => 200,
            'response_headers' => ['content-type: application/json'],
        ]);

        return new PostmarkApiTransport('fixture-server-token', new MockHttpClient($response));
    }

    private function emailWithMimeId(string $messageId): Email
    {
        $email = (new Email())
            ->from('sender@example.test')
            ->to('recipient@example.test')
            ->subject('Fixture')
            ->text('Fixture');
        $email->getHeaders()->addIdHeader('Message-ID', $messageId);

        return $email;
    }
}
