<?php

namespace HiEvents\Services\Application\Handlers\Order\Payment\Stripe;

use Brick\Math\Exception\MathException;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\Exception\RoundingNecessaryException;
use Brick\Money\Exception\UnknownCurrencyException;
use HiEvents\DomainObjects\Enums\StripeRefundRequestStatus;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrganizerDomainObject;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Exceptions\RefundNotPossibleException;
use HiEvents\Mail\Order\OrderRefunded;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\StripeRefundRequestRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\RefundOrderDTO;
use HiEvents\Services\Domain\Order\OrderCancelService;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\CreateStripeRefundRequestDTO;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\StripeRefundRequestRecordDTO;
use HiEvents\Services\Domain\Payment\Stripe\StripePaymentIntentRefundService;
use HiEvents\Services\Domain\Payment\Stripe\StripeProviderObjectLockService;
use HiEvents\Services\Infrastructure\Stripe\StripeClientFactory;
use HiEvents\Values\MoneyValue;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Database\DatabaseManager;
use Stripe\Exception\ApiErrorException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Throwable;

class RefundOrderHandler
{
    public function __construct(
        private readonly StripePaymentIntentRefundService $refundService,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly EventRepositoryInterface $eventRepository,
        private readonly Mailer $mailer,
        private readonly OrderCancelService $orderCancelService,
        private readonly DatabaseManager $databaseManager,
        private readonly StripeClientFactory $stripeClientFactory,
        private readonly StripeRefundRequestRepositoryInterface $refundRequestRepository,
        private readonly StripeProviderObjectLockService $providerObjectLockService,
    ) {}

    /**
     * @throws RefundNotPossibleException
     * @throws ApiErrorException
     * @throws Throwable
     */
    public function handle(RefundOrderDTO $refundOrderDTO): OrderDomainObject
    {
        $request = $this->databaseManager->transaction(
            fn (): StripeRefundRequestRecordDTO => $this->prepareRequest($refundOrderDTO)
        );

        if ($request->providerRefundId === null) {
            $request = $this->sendProviderRequest($request);
        }

        $order = $this->databaseManager->transaction(
            fn (): OrderDomainObject => $this->finalizeAcceptedRequest($request)
        );
        $this->sendNotificationOnce($request, $order);

        return $order;
    }

    private function fetchOrder(int $eventId, int $orderId): OrderDomainObject
    {
        $order = $this->orderRepository
            ->loadRelation(new Relationship(StripePaymentDomainObject::class, name: 'stripe_payment'))
            ->findFirstWhere(['event_id' => $eventId, 'id' => $orderId]);

        if (! $order) {
            throw new ResourceNotFoundException(__('Order :id not found for event :eventId', [
                'id' => $orderId,
                'eventId' => $eventId,
            ]));
        }

        return $order;
    }

    /**
     * @throws RefundNotPossibleException
     * @throws MathException
     * @throws NumberFormatException
     * @throws RoundingNecessaryException
     * @throws UnknownCurrencyException
     */
    private function prepareRequest(RefundOrderDTO $refundOrderDTO): StripeRefundRequestRecordDTO
    {
        $order = $this->fetchOrder($refundOrderDTO->event_id, $refundOrderDTO->order_id);
        $payment = $order->getStripePayment();
        if ($payment === null) {
            throw new RefundNotPossibleException(__('There is no Stripe data associated with this order.'));
        }

        $lockedPaymentIntentId = $payment->getPaymentIntentId();
        $lockedChargeId = $payment->getChargeId();
        $this->providerObjectLockService->acquirePaymentIdentity($lockedPaymentIntentId, $lockedChargeId);

        $order = $this->fetchOrder($refundOrderDTO->event_id, $refundOrderDTO->order_id);
        $payment = $order->getStripePayment();
        if ($payment === null) {
            throw new RefundNotPossibleException(__('There is no Stripe data associated with this order.'));
        }
        if ($payment->getPaymentIntentId() !== $lockedPaymentIntentId
            || $payment->getChargeId() !== $lockedChargeId) {
            throw new RefundNotPossibleException(
                __('The Stripe payment context changed while preparing this refund request.')
            );
        }

        $amount = MoneyValue::fromFloat($refundOrderDTO->amount, $order->getCurrency());
        $claim = $this->refundRequestRepository->claimOrLoad(new CreateStripeRefundRequestDTO(
            requestId: $refundOrderDTO->refund_request_id,
            orderId: $order->getId(),
            stripePaymentId: $payment->getId(),
            paymentIntentId: $payment->getPaymentIntentId(),
            stripeAccountId: $payment->getConnectedAccountId(),
            amountMinor: $amount->toMinorUnit(),
            currency: $order->getCurrency(),
            notifyBuyer: $refundOrderDTO->notify_buyer,
            cancelOrder: $refundOrderDTO->cancel_order,
            refundApplicationFee: $this->refundService->resolveRefundApplicationFee(),
        ));

        if (! $claim->created) {
            return $claim->request;
        }

        $this->validateRefundability($order, $amount);
        $this->markOrderRefundPending($order);

        return $claim->request;
    }

    /**
     * @throws RefundNotPossibleException
     * @throws MathException
     * @throws NumberFormatException
     * @throws RoundingNecessaryException
     * @throws UnknownCurrencyException
     */
    private function validateRefundability(OrderDomainObject $order, MoneyValue $amount): void
    {
        if ($order->getRefundStatus() === OrderRefundStatus::REFUND_PENDING->name) {
            throw new RefundNotPossibleException(
                __('There is already a refund pending for this order. Please wait for it to complete before requesting another one.')
            );
        }

        $remaining = MoneyValue::fromFloat(
            max(0, $order->getTotalGross() - $order->getTotalRefunded()),
            $order->getCurrency(),
        );
        if ($amount->toMinorUnit() > $remaining->toMinorUnit()) {
            throw new RefundNotPossibleException(
                __('The refund amount exceeds the remaining refundable amount.')
            );
        }
    }

    /**
     * @throws ApiErrorException
     * @throws Throwable
     */
    private function sendProviderRequest(
        StripeRefundRequestRecordDTO $request,
    ): StripeRefundRequestRecordDTO {
        $order = $this->fetchOrderForRequest($request);
        $payment = $order->getStripePayment();
        if ($payment === null) {
            throw new RefundNotPossibleException(__('There is no Stripe data associated with this order.'));
        }

        $stripeClient = $this->stripeClientFactory->createForPlatform($payment->getStripePlatformEnum());
        $this->refundRequestRepository->recordAttempt($request->requestId);

        try {
            $refund = $this->refundService->refundPayment(
                amount: MoneyValue::fromMinorUnit($request->amountMinor, $request->currency),
                payment: $payment,
                stripeClient: $stripeClient,
                refundRequestId: $request->requestId,
                refundApplicationFee: $request->refundApplicationFee,
            );
        } catch (Throwable $exception) {
            $this->refundRequestRepository->recordProviderError($request->requestId, $exception::class);

            throw $exception;
        }

        return $this->databaseManager->transaction(function () use ($request, $refund): StripeRefundRequestRecordDTO {
            $this->providerObjectLockService->acquirePaymentIdentity($request->paymentIntentId);

            return $this->refundRequestRepository->recordProviderResult(
                requestId: $request->requestId,
                providerRefundId: (string) $refund->id,
                providerStatus: (string) ($refund->status ?? 'pending'),
            );
        });
    }

    private function finalizeAcceptedRequest(StripeRefundRequestRecordDTO $request): OrderDomainObject
    {
        $order = $this->fetchOrderForRequest($request);
        $payment = $order->getStripePayment();
        if ($payment === null) {
            throw new RefundNotPossibleException(__('There is no Stripe data associated with this order.'));
        }

        $this->providerObjectLockService->acquirePaymentIdentity(
            $payment->getPaymentIntentId(),
            $payment->getChargeId(),
        );
        $lockedRequest = $this->refundRequestRepository->findByRequestId($request->requestId, true);
        if ($lockedRequest === null) {
            throw new RefundNotPossibleException(__('The refund request could not be recovered.'));
        }

        if ($lockedRequest->status === StripeRefundRequestStatus::FAILED->value) {
            return $this->orderRepository->updateFromArray($order->getId(), [
                OrderDomainObjectAbstract::REFUND_STATUS => OrderRefundStatus::REFUND_FAILED->name,
            ]);
        }

        if ($lockedRequest->cancelOrder && ! $lockedRequest->cancelApplied) {
            $this->orderCancelService->cancelOrder($order);
            $this->refundRequestRepository->markCancelApplied($lockedRequest->requestId);
        }

        if ($lockedRequest->status === StripeRefundRequestStatus::SUCCEEDED->value) {
            return $this->orderRepository->findById($order->getId());
        }

        return $this->markOrderRefundPending($order);
    }

    private function fetchOrderForRequest(StripeRefundRequestRecordDTO $request): OrderDomainObject
    {
        $order = $this->orderRepository
            ->loadRelation(new Relationship(StripePaymentDomainObject::class, name: 'stripe_payment'))
            ->findById($request->orderId);

        $payment = $order->getStripePayment();
        if ($payment === null
            || $payment->getId() !== $request->stripePaymentId
            || $payment->getPaymentIntentId() !== $request->paymentIntentId
            || $payment->getConnectedAccountId() !== $request->stripeAccountId) {
            throw new RefundNotPossibleException(
                __('The Stripe payment context changed after this refund request was created.')
            );
        }

        return $order;
    }

    private function sendNotificationOnce(
        StripeRefundRequestRecordDTO $request,
        OrderDomainObject $order,
    ): void {
        if (! $request->notifyBuyer || ! $this->refundRequestRepository->claimNotification($request->requestId)) {
            return;
        }

        try {
            $event = $this->eventRepository
                ->loadRelation(new Relationship(OrganizerDomainObject::class, name: 'organizer'))
                ->loadRelation(EventSettingDomainObject::class)
                ->findById($order->getEventId());
            $this->mailer
                ->to($order->getEmail())
                ->locale($order->getLocale())
                ->send(new OrderRefunded(
                    order: $order,
                    event: $event,
                    organizer: $event->getOrganizer(),
                    eventSettings: $event->getEventSettings(),
                    refundAmount: MoneyValue::fromMinorUnit($request->amountMinor, $request->currency),
                ));
            $this->refundRequestRepository->markNotificationSent($request->requestId);
        } catch (Throwable $exception) {
            $this->refundRequestRepository->releaseNotificationClaim($request->requestId);

            throw $exception;
        }
    }

    private function markOrderRefundPending(OrderDomainObject $order): OrderDomainObject
    {
        return $this->orderRepository->updateFromArray(
            id: $order->getId(),
            attributes: [
                OrderDomainObjectAbstract::REFUND_STATUS => OrderRefundStatus::REFUND_PENDING->name,
            ]
        );
    }
}
