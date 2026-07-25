<?php

namespace HiEvents\Services\Application\Handlers\Order\Payment\Stripe;

use HiEvents\Exceptions\CannotAcceptPaymentException;
use HiEvents\Exceptions\Stripe\StripeLocalPaymentNotFoundException;
use HiEvents\Repository\Interfaces\StripeWebhookEventRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\Payment\Stripe\DTO\StripeWebhookDTO;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\AccountUpdateHandler;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\ChargeRefundUpdatedHandler;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\ChargeSucceededHandler;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\DisputeUpdatedHandler;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\PaymentIntentFailedHandler;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\PaymentIntentSucceededHandler;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\PayoutPaidHandler;
use HiEvents\Services\Infrastructure\Stripe\StripeConfigurationService;
use HiEvents\Services\Infrastructure\Stripe\StripeConnectWebhookContract;
use Illuminate\Log\Logger;
use JsonException;
use Stripe\Charge;
use Stripe\Event;
use Stripe\Exception\SignatureVerificationException;
use Stripe\Webhook;
use Throwable;
use UnexpectedValueException;

class IncomingWebhookHandler
{
    public function __construct(
        private readonly ChargeRefundUpdatedHandler $refundEventHandlerService,
        private readonly ChargeSucceededHandler $chargeSucceededHandler,
        private readonly DisputeUpdatedHandler $disputeUpdatedHandler,
        private readonly PaymentIntentSucceededHandler $paymentIntentSucceededHandler,
        private readonly PaymentIntentFailedHandler $paymentIntentFailedHandler,
        private readonly AccountUpdateHandler $accountUpdateHandler,
        private readonly PayoutPaidHandler $payoutPaidHandler,
        private readonly Logger $logger,
        private readonly StripeWebhookEventRepositoryInterface $webhookEventRepository,
        private readonly StripeConfigurationService $stripeConfigurationService,
    ) {}

    /**
     * @throws SignatureVerificationException
     * @throws JsonException
     * @throws Throwable
     */
    public function handle(StripeWebhookDTO $webhookDTO): void
    {
        try {
            $event = $this->constructEventWithValidPlatform($webhookDTO);

            if (! in_array($event->type, StripeConnectWebhookContract::EVENT_TYPES, true)) {
                $this->logger->debug(__('Received a :event Stripe event, which has no handler', [
                    'event' => $event->type,
                ]), [
                    'event_id' => $event->id,
                    'event_type' => $event->type,
                ]);

                return;
            }

            $claimToken = $this->webhookEventRepository->claim($event->id, $event->type, $event->account);

            if ($claimToken === null) {
                $this->logger->debug('Stripe event already handled or currently processing', [
                    'event_id' => $event->id,
                    'event_type' => $event->type,
                    'stripe_account_id' => $event->account,
                ]);

                return;
            }

            $this->logger->debug('Stripe event received', [
                'event_id' => $event->id,
                'event_type' => $event->type,
                'stripe_account_id' => $event->account,
                'object_id' => $event->data->object->id ?? null,
                'object_type' => $event->data->object->object ?? null,
            ]);

            try {
                switch ($event->type) {
                    case Event::PAYMENT_INTENT_SUCCEEDED:
                        $this->paymentIntentSucceededHandler->handleEvent($event->data->object);
                        break;
                    case Event::PAYMENT_INTENT_PAYMENT_FAILED:
                        $this->paymentIntentFailedHandler->handleEvent(
                            $event->data->object,
                            $event->account,
                            $event->id,
                            $event->type,
                        );
                        break;
                    case Event::CHARGE_SUCCEEDED:
                    case Event::CHARGE_UPDATED:
                        $this->chargeSucceededHandler->handleEvent($event->data->object);
                        break;
                    case Event::REFUND_UPDATED:
                    case Event::REFUND_CREATED:
                        $this->refundEventHandlerService->handleEvent(
                            $event->data->object,
                            $event->account,
                            $event->id,
                            $event->type,
                        );
                        break;
                    case Event::CHARGE_REFUNDED:
                        $this->handleChargeRefunded(
                            $event->data->object,
                            $event->account,
                            $event->id,
                            $event->type,
                        );
                        break;
                    case Event::CHARGE_DISPUTE_CREATED:
                    case Event::CHARGE_DISPUTE_UPDATED:
                    case Event::CHARGE_DISPUTE_CLOSED:
                        $this->disputeUpdatedHandler->handleEvent(
                            $event->data->object,
                            $event->account,
                            $event->id,
                            $event->type,
                            $event->created,
                        );
                        break;
                    case Event::ACCOUNT_UPDATED:
                        $this->accountUpdateHandler->handleEvent($event->data->object);
                        break;
                    case Event::PAYOUT_PAID:
                    case Event::PAYOUT_UPDATED:
                        $this->payoutPaidHandler->handleEvent($event->data->object, $event->account);
                        break;
                }

                $this->webhookEventRepository->markHandled($event->id, $claimToken);
                $this->logger->info('Stripe event marked as handled', [
                    'event_id' => $event->id,
                    'event_type' => $event->type,
                ]);
            } catch (Throwable $exception) {
                try {
                    $this->webhookEventRepository->markFailed($event->id, $claimToken, $exception::class);
                } catch (Throwable $trackingException) {
                    $this->logger->critical('Failed to persist Stripe webhook failure state', [
                        'event_id' => $event->id,
                        'event_type' => $event->type,
                        'exception_class' => $trackingException::class,
                    ]);
                }

                throw $exception;
            }
        } catch (CannotAcceptPaymentException $exception) {
            $this->logSanitizedFailure('Cannot accept payment from Stripe webhook', $exception);
            throw $exception;
        } catch (SignatureVerificationException $exception) {
            $this->logSanitizedFailure('Unable to verify Stripe webhook signature', $exception);
            throw $exception;
        } catch (UnexpectedValueException $exception) {
            $this->logSanitizedFailure('Unexpected value in Stripe webhook', $exception);
            throw $exception;
        } catch (Throwable $exception) {
            $this->logSanitizedFailure('Unhandled Stripe webhook error', $exception);
            throw $exception;
        }
    }

    private function constructEventWithValidPlatform(StripeWebhookDTO $webhookDTO): Event
    {
        $webhookSecrets = $this->stripeConfigurationService->getAllWebhookSecrets();
        $lastException = null;

        foreach ($webhookSecrets as $platform => $webhookSecret) {
            try {
                if (! $webhookSecret) {
                    continue;
                }

                $event = Webhook::constructEvent(
                    $webhookDTO->payload,
                    $webhookDTO->headerSignature,
                    $webhookSecret
                );

                $this->logger->debug('Webhook validated with platform: '.$platform, [
                    'event_id' => $event->id,
                    'platform' => $platform,
                ]);

                return $event;
            } catch (SignatureVerificationException $exception) {
                $lastException = $exception;

                continue;
            }
        }

        throw $lastException ?? new SignatureVerificationException(__('Unable to verify Stripe signature with any platform'));
    }

    private function handleChargeRefunded(
        Charge $charge,
        ?string $stripeAccountId,
        string $eventId,
        string $eventType,
    ): void {
        $refunds = $charge->refunds->data ?? [];

        $missingLocalPayment = null;

        foreach ($refunds as $refund) {
            try {
                $this->refundEventHandlerService->handleEvent(
                    $refund,
                    $stripeAccountId,
                    $eventId,
                    $eventType,
                    $charge->id,
                );
            } catch (StripeLocalPaymentNotFoundException $exception) {
                $missingLocalPayment ??= $exception;
            }
        }

        if ($missingLocalPayment !== null) {
            throw $missingLocalPayment;
        }
    }

    private function logSanitizedFailure(string $message, Throwable $exception): void
    {
        $this->logger->error($message, [
            'exception_class' => $exception::class,
        ]);
    }
}
