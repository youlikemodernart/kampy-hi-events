<?php

namespace HiEvents\Services\Domain\Payment\Stripe\EventHandlers;

use Brick\Math\Exception\MathException;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Money\Exception\UnknownCurrencyException;
use Carbon\Carbon;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\EventSettingDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\StripePaymentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\Status\AttendeeStatus;
use HiEvents\DomainObjects\Status\OrderApplicationFeeStatus;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Exceptions\CannotAcceptPaymentException;
use HiEvents\Exceptions\Stripe\StripeClientConfigurationException;
use HiEvents\Repository\Eloquent\StripePaymentsRepository;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\AffiliateRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventSettingsRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\StripeDisputeRepositoryInterface;
use HiEvents\Services\Domain\Order\OrderApplicationFeeService;
use HiEvents\Services\Domain\Order\OrderEffectOutboxService;
use HiEvents\Services\Domain\Payment\Stripe\StripeProviderErrorSanitizer;
use HiEvents\Services\Domain\Payment\Stripe\StripeProviderObjectLockService;
use HiEvents\Services\Domain\Payment\Stripe\StripeRefundExpiredOrderService;
use HiEvents\Services\Domain\Product\ProductQuantityUpdateService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\PaymentIntent;
use Throwable;

class PaymentIntentSucceededHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly StripePaymentsRepository $stripePaymentsRepository,
        private readonly AffiliateRepositoryInterface $affiliateRepository,
        private readonly ProductQuantityUpdateService $quantityUpdateService,
        private readonly StripeRefundExpiredOrderService $refundExpiredOrderService,
        private readonly AttendeeRepositoryInterface $attendeeRepository,
        private readonly DatabaseManager $databaseManager,
        private readonly LoggerInterface $logger,
        private readonly OrderApplicationFeeService $orderApplicationFeeService,
        private readonly EventSettingsRepositoryInterface $eventSettingsRepository,
        private readonly StripeDisputeRepositoryInterface $stripeDisputeRepository,
        private readonly StripeProviderObjectLockService $providerObjectLockService,
        private readonly OrderEffectOutboxService $orderEffectOutboxService,
    ) {}

    /**
     * @throws Throwable
     */
    public function handleEvent(PaymentIntent $paymentIntent): void
    {
        $updatedOrder = $this->databaseManager->transaction(function () use ($paymentIntent): OrderDomainObject {
            $this->providerObjectLockService->acquirePaymentIdentity(
                $paymentIntent->id,
                $this->chargeId($paymentIntent),
            );

            /** @var StripePaymentDomainObjectAbstract $stripePayment */
            $stripePayment = $this->stripePaymentsRepository
                ->loadRelation(new Relationship(OrderDomainObject::class, name: 'order'))
                ->findFirstWhere([
                    StripePaymentDomainObjectAbstract::PAYMENT_INTENT_ID => $paymentIntent->id,
                ]);

            if (! $stripePayment) {
                $this->logger->error('Payment intent not found when handling payment intent succeeded event', [
                    'payment_intent_id' => $paymentIntent->id,
                    'payment_intent_status' => $paymentIntent->status,
                ]);

                throw new RuntimeException('Stripe payment is not locally available for the succeeded PaymentIntent.');
            }

            if ($this->isDurablyHandled($stripePayment)) {
                $this->linkPendingDisputes($stripePayment, $paymentIntent);

                return $stripePayment->getOrder();
            }

            $this->validatePaymentAndOrderStatus($stripePayment, $paymentIntent);

            $this->updateStripePaymentInfo($paymentIntent, $stripePayment);
            $this->linkPendingDisputes($stripePayment, $paymentIntent);

            $updatedOrder = $this->updateOrderStatuses($stripePayment);

            $this->updateAttendeeStatuses($updatedOrder);

            $this->quantityUpdateService->updateQuantitiesFromOrder($updatedOrder);

            /** @var EventSettingDomainObject $eventSettings */
            $eventSettings = $this->eventSettingsRepository->findFirstWhere([
                EventSettingDomainObjectAbstract::EVENT_ID => $updatedOrder->getEventId(),
            ]);

            event(new OrderStatusChangedEvent(
                order: $updatedOrder,
                sendEmails: false,
                createInvoice: $eventSettings->getEnableInvoicing(),
                updateStatistics: false,
            ));

            $this->orderEffectOutboxService->enqueueCompletedOrder(
                $updatedOrder->getId(),
                OrderEffectOutboxService::TRANSITION_STRIPE_COMPLETED,
                DomainEventType::ORDER_CREATED,
            );

            $this->storeApplicationFeePayment($updatedOrder, $paymentIntent);

            return $updatedOrder;
        });

        $this->markPaymentIntentAsHandled($paymentIntent, $updatedOrder);
    }

    private function updateOrderStatuses(StripePaymentDomainObjectAbstract $stripePayment): OrderDomainObject
    {
        $updatedOrder = $this->orderRepository
            ->loadRelation(OrderItemDomainObject::class)
            ->updateFromArray($stripePayment->getOrderId(), [
                OrderDomainObjectAbstract::PAYMENT_STATUS => OrderPaymentStatus::PAYMENT_RECEIVED->name,
                OrderDomainObjectAbstract::STATUS => OrderStatus::COMPLETED->name,
                OrderDomainObjectAbstract::PAYMENT_PROVIDER => PaymentProviders::STRIPE->value,
            ]);

        // Update affiliate sales if this order has an affiliate
        if ($updatedOrder->getAffiliateId()) {
            $this->affiliateRepository->incrementSales(
                affiliateId: $updatedOrder->getAffiliateId(),
                amount: $updatedOrder->getTotalGross()
            );
        }

        return $updatedOrder;
    }

    private function updateStripePaymentInfo(PaymentIntent $paymentIntent, StripePaymentDomainObjectAbstract $stripePayment): void
    {
        $this->stripePaymentsRepository->updateWhere(
            attributes: [
                StripePaymentDomainObjectAbstract::LAST_ERROR => StripeProviderErrorSanitizer::sanitize(
                    $paymentIntent->last_payment_error,
                ),
                StripePaymentDomainObjectAbstract::AMOUNT_RECEIVED => $paymentIntent->amount_received,
                StripePaymentDomainObjectAbstract::PAYMENT_METHOD_ID => is_string($paymentIntent->payment_method)
                    ? $paymentIntent->payment_method
                    : $paymentIntent->payment_method?->id,
                StripePaymentDomainObjectAbstract::CHARGE_ID => is_string($paymentIntent->latest_charge)
                    ? $paymentIntent->latest_charge
                    : $paymentIntent->latest_charge?->id,
            ],
            where: [
                StripePaymentDomainObjectAbstract::PAYMENT_INTENT_ID => $paymentIntent->id,
                StripePaymentDomainObjectAbstract::ORDER_ID => $stripePayment->getOrderId(),
            ]);
    }

    /**
     * If the order has expired (reserved_until is in the past), refund the payment and throw an exception.
     * This does seem quite extreme, but it ensures we don't oversell products. As far as I can see
     * this is how Ticketmaster and other ticketing systems work.
     *
     * @throws ApiErrorException
     * @throws RoundingNecessaryException
     * @throws CannotAcceptPaymentException
     * @throws MathException
     * @throws UnknownCurrencyException
     * @throws NumberFormatException
     * @throws StripeClientConfigurationException
     *
     * @todo We could check to see if there are products available, and if so, complete the order.
     *       This would be a better user experience.
     */
    private function handleExpiredOrder(
        StripePaymentDomainObjectAbstract $stripePayment,
        PaymentIntent $paymentIntent,
    ): void {
        if ((new Carbon($stripePayment->getOrder()?->getReservedUntil()))->isPast()) {
            $this->refundExpiredOrderService->refundExpiredOrder(
                paymentIntent: $paymentIntent,
                stripePayment: $stripePayment,
                order: $stripePayment->getOrder(),
            );

            throw new CannotAcceptPaymentException(
                __('Payment was successful, but order has expired. Order: :id', [
                    'id' => $stripePayment->getOrderId(),
                ])
            );
        }
    }

    /**
     * @throws ApiErrorException
     * @throws RoundingNecessaryException
     * @throws CannotAcceptPaymentException
     * @throws MathException
     * @throws UnknownCurrencyException
     * @throws NumberFormatException|StripeClientConfigurationException
     */
    private function validatePaymentAndOrderStatus(
        StripePaymentDomainObjectAbstract $stripePayment,
        PaymentIntent $paymentIntent
    ): void {
        if (! in_array($stripePayment->getOrder()?->getPaymentStatus(), [
            OrderPaymentStatus::AWAITING_PAYMENT->name,
            OrderPaymentStatus::PAYMENT_FAILED->name,
        ], true)) {
            throw new CannotAcceptPaymentException(
                __('Order is not awaiting payment. Order: :id',
                    ['id' => $stripePayment->getOrderId()]
                )
            );
        }

        $this->handleExpiredOrder($stripePayment, $paymentIntent);
    }

    private function updateAttendeeStatuses(OrderDomainObject $updatedOrder): void
    {
        $this->attendeeRepository->updateWhere(
            attributes: [
                'status' => AttendeeStatus::ACTIVE->name,
            ],
            where: [
                'order_id' => $updatedOrder->getId(),
                'status' => AttendeeStatus::AWAITING_PAYMENT->name,
            ],
        );
    }

    private function isDurablyHandled(StripePaymentDomainObjectAbstract $stripePayment): bool
    {
        return $stripePayment->getOrder()?->getStatus() === OrderStatus::COMPLETED->name
            && $stripePayment->getOrder()?->getPaymentStatus() === OrderPaymentStatus::PAYMENT_RECEIVED->name;
    }

    private function linkPendingDisputes(
        StripePaymentDomainObjectAbstract $stripePayment,
        PaymentIntent $paymentIntent,
    ): void {
        $this->stripeDisputeRepository->linkPendingToPayment(
            orderId: $stripePayment->getOrderId(),
            stripePaymentId: $stripePayment->getId(),
            paymentIntentId: $paymentIntent->id,
            chargeId: $this->chargeId($paymentIntent),
            stripeAccountId: $stripePayment->getConnectedAccountId(),
        );
    }

    private function chargeId(PaymentIntent $paymentIntent): ?string
    {
        return is_string($paymentIntent->latest_charge)
            ? $paymentIntent->latest_charge
            : $paymentIntent->latest_charge?->id;
    }

    private function markPaymentIntentAsHandled(PaymentIntent $paymentIntent, OrderDomainObject $updatedOrder): void
    {
        $this->logger->info('Stripe payment intent succeeded event handled', [
            'payment_intent' => $paymentIntent->id,
            'order_id' => $updatedOrder->getId(),
            'amount_received' => $paymentIntent->amount_received,
            'currency' => $paymentIntent->currency,
        ]);

    }

    private function storeApplicationFeePayment(OrderDomainObject $updatedOrder, PaymentIntent $paymentIntent): void
    {
        $this->orderApplicationFeeService->createOrderApplicationFee(
            orderId: $updatedOrder->getId(),
            applicationFeeAmountMinorUnit: $paymentIntent->application_fee_amount ?? 0,
            orderApplicationFeeStatus: OrderApplicationFeeStatus::PAID,
            paymentMethod: PaymentProviders::STRIPE,
            currency: $updatedOrder->getCurrency(),
        );
    }
}
