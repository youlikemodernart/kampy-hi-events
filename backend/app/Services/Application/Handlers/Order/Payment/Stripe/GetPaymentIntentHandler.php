<?php

namespace HiEvents\Services\Application\Handlers\Order\Payment\Stripe;

use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\Payment\Stripe\DTO\StripePaymentIntentPublicDTO;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\PaymentIntentSucceededHandler;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use HiEvents\Services\Infrastructure\Stripe\StripeClientFactory;
use Psr\Log\LoggerInterface;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;

class GetPaymentIntentHandler
{
    public function __construct(
        private readonly StripeClientFactory           $stripeClientFactory,
        private readonly OrderRepositoryInterface          $orderRepository,
        private readonly LoggerInterface                   $logger,
        private readonly PaymentIntentSucceededHandler     $paymentIntentSucceededHandler,
        private readonly CheckoutSessionManagementService  $sessionManagementService,
    )
    {
    }

    public function handle(int $eventId, string $orderShortId): StripePaymentIntentPublicDTO
    {
        /** @var OrderDomainObject $order */
        $order = $this->orderRepository
            ->loadRelation(new Relationship(
                domainObject: StripePaymentDomainObject::class,
                name: 'stripe_payment',
            ))
            ->findFirstWhere([
                'event_id' => $eventId,
                'short_id' => $orderShortId
            ]);

        if ($order === null || $order->getStripePayment() === null) {
            throw new ResourceNotFoundException(__('Payment intent not found'));
        }

        if ($order->getPaymentStatus() !== OrderPaymentStatus::PAYMENT_RECEIVED->name
            && ($order->getSessionId() === null || !$this->sessionManagementService->verifySession($order->getSessionId()))) {
            throw new UnauthorizedException(__('Sorry, we could not verify your session. Please create a new order.'));
        }

        $accountId = $order->getStripePayment()->getConnectedAccountId();
        $paymentPlatform = $order->getStripePayment()->getStripePlatformEnum();

        try {
            $stripeClient = $this->stripeClientFactory->createForPlatform($paymentPlatform);
            $paymentIntent = $stripeClient->paymentIntents->retrieve(
                id: $order->getStripePayment()->getPaymentIntentId(),
                opts: $accountId ? ['stripe_account' => $accountId] : []
            );
        } catch (ApiErrorException $e) {
            $this->logger->error('Failed to retrieve payment intent', [
                'error' => $e->getMessage(),
                'order_id' => $order->getId(),
                'order_short_id' => $order->getShortId(),
                'payment_intent_id' => $order->getStripePayment()->getPaymentIntentId(),
            ]);

            throw new ResourceNotFoundException('Payment intent not found: ' . $e->getMessage());
        }

        // If the payment intent is a success and the order's payment status is not received, we manually handle the event here.
        // This is because the webhook may not have been received yet, or has failed for some reason.
        // This is a safety net to ensure the order is updated correctly.
        if ($paymentIntent->status === 'succeeded' && $order->getPaymentStatus() !== OrderPaymentStatus::PAYMENT_RECEIVED->name) {
            $this->paymentIntentSucceededHandler->handleEvent($paymentIntent);
        }

        return StripePaymentIntentPublicDTO::fromArray([
            'paymentIntentId' => $paymentIntent->id,
            'status' => $paymentIntent->status,
            'amount' => $paymentIntent->amount,
        ]);
    }
}
