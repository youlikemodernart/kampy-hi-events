<?php

declare(strict_types=1);

namespace Tests\Unit\Http\Actions\Common\Webhooks;

use HiEvents\Exceptions\Stripe\StripeLocalPaymentNotFoundException;
use HiEvents\Exceptions\StripeWebhookEventClaimBusyException;
use HiEvents\Http\Actions\Common\Webhooks\StripeIncomingWebhookAction;
use HiEvents\Services\Application\Handlers\Order\Payment\Stripe\DTO\StripeWebhookDTO;
use HiEvents\Services\Application\Handlers\Order\Payment\Stripe\IncomingWebhookHandler;
use Illuminate\Http\Request;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class StripeIncomingWebhookActionTest extends TestCase
{
    public function test_returns_success_only_after_synchronous_handler_completion(): void
    {
        $handler = Mockery::mock(IncomingWebhookHandler::class);
        $handler->shouldReceive('handle')
            ->once()
            ->with(Mockery::on(static fn (StripeWebhookDTO $dto): bool => $dto->headerSignature === 't=1,v1=test'
                && $dto->payload === '{"id":"evt_test"}'
            ));

        $request = Request::create(
            '/public/webhooks/stripe',
            'POST',
            server: ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=test'],
            content: '{"id":"evt_test"}',
        );

        $response = (new StripeIncomingWebhookAction($handler))($request);

        self::assertSame(204, $response->getStatusCode());
    }

    public function test_returns_conflict_while_another_worker_owns_the_event(): void
    {
        $handler = Mockery::mock(IncomingWebhookHandler::class);
        $handler->shouldReceive('handle')
            ->once()
            ->andThrow(new StripeWebhookEventClaimBusyException);

        $request = Request::create(
            '/public/webhooks/stripe',
            'POST',
            server: ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=test'],
            content: '{"id":"evt_busy"}',
        );

        $response = (new StripeIncomingWebhookAction($handler))($request);

        self::assertSame(409, $response->getStatusCode());
    }

    public function test_returns_service_unavailable_for_retryable_missing_local_payment(): void
    {
        $handler = Mockery::mock(IncomingWebhookHandler::class);
        $handler->shouldReceive('handle')
            ->once()
            ->andThrow(new StripeLocalPaymentNotFoundException);

        $request = Request::create(
            '/public/webhooks/stripe',
            'POST',
            server: ['HTTP_STRIPE_SIGNATURE' => 't=1,v1=test'],
            content: '{"id":"evt_missing"}',
        );

        $response = (new StripeIncomingWebhookAction($handler))($request);

        self::assertSame(503, $response->getStatusCode());
    }

    public function test_returns_bad_request_when_processing_fails(): void
    {
        $handler = Mockery::mock(IncomingWebhookHandler::class);
        $handler->shouldReceive('handle')
            ->once()
            ->andThrow(new RuntimeException('private provider detail'));

        $request = Request::create(
            '/public/webhooks/stripe',
            'POST',
            server: ['HTTP_STRIPE_SIGNATURE' => 'invalid'],
            content: '{"private":"must-not-be-logged"}',
        );

        $response = (new StripeIncomingWebhookAction($handler))($request);

        self::assertSame(400, $response->getStatusCode());
    }
}
