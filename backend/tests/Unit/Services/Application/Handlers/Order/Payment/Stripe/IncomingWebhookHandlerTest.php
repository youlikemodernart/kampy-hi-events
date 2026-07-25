<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Application\Handlers\Order\Payment\Stripe;

use HiEvents\Exceptions\Stripe\StripeLocalPaymentNotFoundException;
use HiEvents\Repository\Interfaces\StripeWebhookEventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\Payment\Stripe\DTO\StripeWebhookDTO;
use HiEvents\Services\Application\Handlers\Order\Payment\Stripe\IncomingWebhookHandler;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\AccountUpdateHandler;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\ChargeRefundUpdatedHandler;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\ChargeSucceededHandler;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\DisputeUpdatedHandler;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\PaymentIntentFailedHandler;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\PaymentIntentSucceededHandler;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\PayoutPaidHandler;
use HiEvents\Services\Infrastructure\Stripe\StripeConfigurationService;
use Illuminate\Log\Logger;
use Mockery;
use Mockery\MockInterface;
use ReflectionClass;
use RuntimeException;
use Stripe\Dispute;
use Stripe\Exception\SignatureVerificationException;
use Stripe\PaymentIntent;
use Stripe\Refund;
use Tests\TestCase;

class IncomingWebhookHandlerTest extends TestCase
{
    private const WEBHOOK_SECRET = 'whsec_test_secret';

    private ChargeRefundUpdatedHandler|MockInterface $refundHandler;

    private ChargeSucceededHandler|MockInterface $chargeHandler;

    private DisputeUpdatedHandler|MockInterface $disputeHandler;

    private PaymentIntentSucceededHandler|MockInterface $paymentSucceededHandler;

    private PaymentIntentFailedHandler $paymentFailedHandler;

    private AccountUpdateHandler|MockInterface $accountUpdateHandler;

    private PayoutPaidHandler|MockInterface $payoutHandler;

    private Logger|MockInterface $logger;

    private StripeWebhookEventRepositoryInterface|MockInterface $eventRepository;

    private StripeConfigurationService|MockInterface $stripeConfiguration;

    private IncomingWebhookHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->refundHandler = Mockery::mock(ChargeRefundUpdatedHandler::class);
        $this->chargeHandler = Mockery::mock(ChargeSucceededHandler::class);
        $this->disputeHandler = Mockery::mock(DisputeUpdatedHandler::class);
        $this->paymentSucceededHandler = Mockery::mock(PaymentIntentSucceededHandler::class);
        $this->paymentFailedHandler = (new ReflectionClass(PaymentIntentFailedHandler::class))->newInstanceWithoutConstructor();
        $this->accountUpdateHandler = Mockery::mock(AccountUpdateHandler::class);
        $this->payoutHandler = Mockery::mock(PayoutPaidHandler::class);
        $this->logger = Mockery::mock(Logger::class);
        $this->eventRepository = Mockery::mock(StripeWebhookEventRepositoryInterface::class);
        $this->stripeConfiguration = Mockery::mock(StripeConfigurationService::class);

        $this->stripeConfiguration
            ->shouldReceive('getAllWebhookSecrets')
            ->andReturn(['primary' => self::WEBHOOK_SECRET]);

        $this->handler = new IncomingWebhookHandler(
            $this->refundHandler,
            $this->chargeHandler,
            $this->disputeHandler,
            $this->paymentSucceededHandler,
            $this->paymentFailedHandler,
            $this->accountUpdateHandler,
            $this->payoutHandler,
            $this->logger,
            $this->eventRepository,
            $this->stripeConfiguration,
        );
    }

    public function test_persists_handled_identity_without_logging_provider_payload(): void
    {
        $dto = $this->signedPaymentIntentSucceededEvent();

        $this->expectValidationLog();
        $this->logger->shouldReceive('debug')
            ->once()
            ->with('Stripe event received', Mockery::on($this->isSanitizedEventContext(...)));
        $this->logger->shouldReceive('info')
            ->once()
            ->with('Stripe event marked as handled', [
                'event_id' => 'evt_test_success',
                'event_type' => 'payment_intent.succeeded',
            ]);

        $this->eventRepository->shouldReceive('claim')
            ->once()
            ->with('evt_test_success', 'payment_intent.succeeded', 'acct_test_connected')
            ->andReturn('claim_test_success');
        $this->eventRepository->shouldReceive('markHandled')
            ->once()
            ->with('evt_test_success', 'claim_test_success');
        $this->eventRepository->shouldReceive('markFailed')->never();

        $this->paymentSucceededHandler->shouldReceive('handleEvent')
            ->once()
            ->with(Mockery::type(PaymentIntent::class));

        $this->handler->handle($dto);
    }

    public function test_duplicate_event_does_not_reprocess_or_log_provider_object(): void
    {
        $dto = $this->signedPaymentIntentSucceededEvent();

        $this->expectValidationLog();
        $this->logger->shouldReceive('debug')
            ->once()
            ->with('Stripe event already handled or currently processing', [
                'event_id' => 'evt_test_success',
                'event_type' => 'payment_intent.succeeded',
                'stripe_account_id' => 'acct_test_connected',
            ]);

        $this->eventRepository->shouldReceive('claim')->once()->andReturnNull();
        $this->eventRepository->shouldReceive('markHandled')->never();
        $this->eventRepository->shouldReceive('markFailed')->never();
        $this->paymentSucceededHandler->shouldReceive('handleEvent')->never();

        $this->handler->handle($dto);
    }

    public function test_failed_handler_records_only_exception_class_and_allows_retry(): void
    {
        $dto = $this->signedPaymentIntentSucceededEvent();

        $this->expectValidationLog();
        $this->logger->shouldReceive('debug')
            ->once()
            ->with('Stripe event received', Mockery::on($this->isSanitizedEventContext(...)));
        $this->logger->shouldReceive('error')
            ->once()
            ->with('Unhandled Stripe webhook error', [
                'exception_class' => RuntimeException::class,
            ]);

        $this->eventRepository->shouldReceive('claim')->once()->andReturn('claim_test_failure');
        $this->eventRepository->shouldReceive('markHandled')->never();
        $this->eventRepository->shouldReceive('markFailed')
            ->once()
            ->with('evt_test_success', 'claim_test_failure', RuntimeException::class);

        $this->paymentSucceededHandler->shouldReceive('handleEvent')
            ->once()
            ->andThrow(new RuntimeException('private provider detail'));

        $this->expectException(RuntimeException::class);
        $this->handler->handle($dto);
    }

    public function test_charge_refunded_attempts_every_refund_before_propagating_missing_local_failure(): void
    {
        $dto = $this->signedEvent(json_encode([
            'id' => 'evt_test_refunds',
            'object' => 'event',
            'account' => 'acct_test_connected',
            'api_version' => '2026-04-22.dahlia',
            'created' => time(),
            'data' => [
                'object' => [
                    'id' => 'ch_test_refunds',
                    'object' => 'charge',
                    'amount' => 5695,
                    'currency' => 'usd',
                    'refunds' => [
                        'object' => 'list',
                        'data' => [
                            [
                                'id' => 're_test_one',
                                'object' => 'refund',
                                'amount' => 1000,
                                'payment_intent' => 'pi_test_refunds',
                                'charge' => 'ch_test_refunds',
                                'status' => 'succeeded',
                            ],
                            [
                                'id' => 're_test_two',
                                'object' => 'refund',
                                'amount' => 4695,
                                'payment_intent' => 'pi_test_refunds',
                                'charge' => 'ch_test_refunds',
                                'status' => 'succeeded',
                            ],
                        ],
                        'has_more' => false,
                        'url' => '/v1/charges/ch_test_refunds/refunds',
                    ],
                ],
            ],
            'livemode' => false,
            'pending_webhooks' => 1,
            'type' => 'charge.refunded',
        ], JSON_THROW_ON_ERROR));
        $seenRefunds = [];

        $this->logger->shouldReceive('debug')->once()->with(
            'Webhook validated with platform: primary',
            ['event_id' => 'evt_test_refunds', 'platform' => 'primary'],
        );
        $this->logger->shouldReceive('debug')->once()->with('Stripe event received', Mockery::type('array'));
        $this->logger->shouldReceive('error')->once()->with(
            'Unhandled Stripe webhook error',
            ['exception_class' => StripeLocalPaymentNotFoundException::class],
        );
        $this->eventRepository->shouldReceive('claim')->once()->andReturn('claim_test_refunds');
        $this->eventRepository->shouldReceive('markHandled')->never();
        $this->eventRepository->shouldReceive('markFailed')->once()->with(
            'evt_test_refunds',
            'claim_test_refunds',
            StripeLocalPaymentNotFoundException::class,
        );
        $this->refundHandler->shouldReceive('handleEvent')
            ->twice()
            ->andReturnUsing(static function (
                Refund $refund,
                ?string $accountId,
                string $eventId,
                string $eventType,
                ?string $chargeId,
            ) use (&$seenRefunds): void {
                self::assertSame('acct_test_connected', $accountId);
                self::assertSame('evt_test_refunds', $eventId);
                self::assertSame('charge.refunded', $eventType);
                self::assertSame('ch_test_refunds', $chargeId);
                $seenRefunds[] = $refund->id;

                throw new StripeLocalPaymentNotFoundException;
            });

        try {
            $this->handler->handle($dto);
            self::fail('Missing local refunds were unexpectedly acknowledged.');
        } catch (StripeLocalPaymentNotFoundException) {
            self::assertSame(['re_test_one', 're_test_two'], $seenRefunds);
        }
    }

    public function test_dispute_event_is_persisted_through_the_dispute_handler(): void
    {
        $dto = $this->signedEvent(json_encode([
            'id' => 'evt_test_dispute',
            'object' => 'event',
            'account' => 'acct_test_connected',
            'api_version' => '2026-04-22.dahlia',
            'created' => time(),
            'data' => [
                'object' => [
                    'id' => 'du_test_dispute',
                    'object' => 'dispute',
                    'amount' => 5695,
                    'charge' => 'ch_test_dispute',
                    'currency' => 'usd',
                    'payment_intent' => 'pi_test_dispute',
                    'reason' => 'fraudulent',
                    'status' => 'needs_response',
                ],
            ],
            'livemode' => false,
            'pending_webhooks' => 1,
            'type' => 'charge.dispute.created',
        ], JSON_THROW_ON_ERROR));

        $this->logger->shouldReceive('debug')->once()->with(
            'Webhook validated with platform: primary',
            ['event_id' => 'evt_test_dispute', 'platform' => 'primary'],
        );
        $this->logger->shouldReceive('debug')->once()->with(
            'Stripe event received',
            Mockery::on(static fn (array $context): bool => $context === [
                'event_id' => 'evt_test_dispute',
                'event_type' => 'charge.dispute.created',
                'stripe_account_id' => 'acct_test_connected',
                'object_id' => 'du_test_dispute',
                'object_type' => 'dispute',
            ]),
        );
        $this->logger->shouldReceive('info')->once()->with(
            'Stripe event marked as handled',
            ['event_id' => 'evt_test_dispute', 'event_type' => 'charge.dispute.created'],
        );

        $this->eventRepository->shouldReceive('claim')->once()->andReturn('claim_test_dispute');
        $this->eventRepository->shouldReceive('markHandled')->once()->with('evt_test_dispute', 'claim_test_dispute');
        $this->eventRepository->shouldReceive('markFailed')->never();
        $this->disputeHandler->shouldReceive('handleEvent')
            ->once()
            ->withArgs(static fn (
                Dispute $dispute,
                ?string $accountId,
                string $eventId,
                string $eventType,
                int $eventCreated,
            ): bool => $dispute->id === 'du_test_dispute'
                && $accountId === 'acct_test_connected'
                && $eventId === 'evt_test_dispute'
                && $eventType === 'charge.dispute.created'
                && $eventCreated > 0);

        $this->handler->handle($dto);
    }

    public function test_invalid_signature_is_logged_without_payload_or_signature(): void
    {
        $payload = $this->paymentIntentSucceededPayload();
        $dto = new StripeWebhookDTO(
            headerSignature: 't=1,v1=invalid',
            payload: $payload,
        );

        $this->logger->shouldReceive('error')
            ->once()
            ->with('Unable to verify Stripe webhook signature', [
                'exception_class' => SignatureVerificationException::class,
            ]);
        $this->eventRepository->shouldReceive('claim')->never();

        $this->expectException(SignatureVerificationException::class);
        $this->handler->handle($dto);
    }

    private function signedPaymentIntentSucceededEvent(): StripeWebhookDTO
    {
        return $this->signedEvent($this->paymentIntentSucceededPayload());
    }

    private function signedEvent(string $payload): StripeWebhookDTO
    {
        $timestamp = time();
        $signature = hash_hmac('sha256', $timestamp.'.'.$payload, self::WEBHOOK_SECRET);

        return new StripeWebhookDTO(
            headerSignature: 't='.$timestamp.',v1='.$signature,
            payload: $payload,
        );
    }

    private function paymentIntentSucceededPayload(): string
    {
        return json_encode([
            'id' => 'evt_test_success',
            'object' => 'event',
            'account' => 'acct_test_connected',
            'api_version' => '2026-04-22.dahlia',
            'created' => time(),
            'data' => [
                'object' => [
                    'id' => 'pi_test_success',
                    'object' => 'payment_intent',
                    'amount' => 5695,
                    'currency' => 'usd',
                    'metadata' => [
                        'private_candidate' => 'must-not-be-logged',
                    ],
                    'status' => 'succeeded',
                ],
            ],
            'livemode' => false,
            'pending_webhooks' => 1,
            'type' => 'payment_intent.succeeded',
        ], JSON_THROW_ON_ERROR);
    }

    private function expectValidationLog(): void
    {
        $this->logger->shouldReceive('debug')
            ->once()
            ->with('Webhook validated with platform: primary', [
                'event_id' => 'evt_test_success',
                'platform' => 'primary',
            ]);
    }

    private function isSanitizedEventContext(array $context): bool
    {
        return $context === [
            'event_id' => 'evt_test_success',
            'event_type' => 'payment_intent.succeeded',
            'stripe_account_id' => 'acct_test_connected',
            'object_id' => 'pi_test_success',
            'object_type' => 'payment_intent',
        ];
    }
}
