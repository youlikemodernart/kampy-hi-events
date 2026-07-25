<?php

namespace HiEvents\Services\Domain\Payment\Stripe\EventHandlers;

use Brick\Money\Money;
use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\Enums\StripeWebhookReconciliationReason;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\Exceptions\Stripe\StripeLocalPaymentNotFoundException;
use HiEvents\Repository\Interfaces\OrderRefundRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\StripePaymentsRepositoryInterface;
use HiEvents\Repository\Interfaces\StripeRefundRequestRepositoryInterface;
use HiEvents\Repository\Interfaces\StripeWebhookReconciliationRepositoryInterface;
use HiEvents\Services\Domain\EventStatistics\EventStatisticsRefundService;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\StripeWebhookReconciliationDTO;
use HiEvents\Services\Domain\Payment\Stripe\StripeProviderObjectLockService;
use HiEvents\Services\Infrastructure\DomainEvents\DomainEventDispatcherService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use HiEvents\Services\Infrastructure\DomainEvents\Events\OrderEvent;
use HiEvents\Values\MoneyValue;
use Illuminate\Database\DatabaseManager;
use Illuminate\Log\Logger;
use Illuminate\Support\Str;
use Stripe\Refund;
use Throwable;

class ChargeRefundUpdatedHandler
{
    public function __construct(
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly StripePaymentsRepositoryInterface $stripePaymentsRepository,
        private readonly Logger $logger,
        private readonly DatabaseManager $databaseManager,
        private readonly EventStatisticsRefundService $eventStatisticsRefundService,
        private readonly OrderRefundRepositoryInterface $orderRefundRepository,
        private readonly DomainEventDispatcherService $domainEventDispatcherService,
        private readonly StripeWebhookReconciliationRepositoryInterface $reconciliationRepository,
        private readonly StripeProviderObjectLockService $providerObjectLockService,
        private readonly StripeRefundRequestRepositoryInterface $refundRequestRepository,
    ) {}

    /**
     * @throws Throwable
     */
    public function handleEvent(
        Refund $refund,
        ?string $stripeAccountId,
        string $eventId,
        string $eventType,
        ?string $parentChargeId = null,
    ): void {
        $paymentIntentId = $this->paymentIntentId($refund);
        $chargeId = $parentChargeId ?? $this->chargeId($refund);
        $pendingReconciliation = $this->reconciliation(
            refund: $refund,
            stripeAccountId: $stripeAccountId,
            eventId: $eventId,
            eventType: $eventType,
            paymentIntentId: $paymentIntentId,
            chargeId: $chargeId,
        );

        $handled = $this->databaseManager->transaction(function () use (
            $refund,
            $paymentIntentId,
            $chargeId,
            $pendingReconciliation,
        ): bool {
            $this->providerObjectLockService->acquirePaymentIdentity($paymentIntentId, $chargeId);

            $stripePayment = $this->stripePaymentsRepository->findFirstWhere(
                $paymentIntentId !== null
                    ? ['payment_intent_id' => $paymentIntentId]
                    : ['charge_id' => $chargeId],
            );

            if (! $stripePayment) {
                $exception = new StripeLocalPaymentNotFoundException;
                $this->reconciliationRepository->recordPending($pendingReconciliation, $exception::class);

                return false;
            }

            $resolvedReconciliation = new StripeWebhookReconciliationDTO(
                eventId: $pendingReconciliation->eventId,
                eventType: $pendingReconciliation->eventType,
                stripeAccountId: $pendingReconciliation->stripeAccountId,
                providerObjectType: $pendingReconciliation->providerObjectType,
                providerObjectId: $pendingReconciliation->providerObjectId,
                paymentIntentId: $pendingReconciliation->paymentIntentId,
                chargeId: $pendingReconciliation->chargeId,
                refundId: $pendingReconciliation->refundId,
                orderId: $stripePayment->getOrderId(),
                stripePaymentId: $stripePayment->getId(),
                reason: StripeWebhookReconciliationReason::LOCAL_PAYMENT_MISSING,
            );
            $this->reconciliationRepository->resolveExisting($resolvedReconciliation);
            $this->linkRefundRequest(
                $refund,
                $stripePayment->getOrderId(),
                $stripePayment->getId(),
                $stripePayment->getPaymentIntentId(),
                $stripePayment->getConnectedAccountId(),
            );

            $existingRefund = $this->orderRefundRepository->findFirstWhere([
                'payment_provider' => PaymentProviders::STRIPE->value,
                'refund_id' => $refund->id,
            ]);

            if ($existingRefund) {
                $this->logger->info(__('Refund already processed'), [
                    'refund_id' => $refund->id,
                    'payment_intent_id' => $paymentIntentId,
                    'existing_refund_id' => $existingRefund->getId(),
                ]);

                return true;
            }

            $order = $this->orderRepository->findById($stripePayment->getOrderId());

            if ($refund->status !== 'succeeded') {
                $this->handleFailure($refund, $order, $paymentIntentId);

                return true;
            }

            $refundedAmount = $this->amountAsFloat($refund->amount, $order->getCurrency());

            $this->updateOrderRefundedAmount($order->getId(), $refundedAmount);
            $this->updateOrderStatus($order, $refundedAmount);
            $this->updateEventStatistics($order, MoneyValue::fromMinorUnit($refund->amount, $order->getCurrency()));
            $this->createOrderRefund($refund, $order, $refundedAmount, $paymentIntentId);

            $this->logger->info(__('Stripe refund successful'), [
                'order_id' => $order->getId(),
                'refunded_amount' => $refundedAmount,
                'currency' => $order->getCurrency(),
                'refund_id' => $refund->id,
            ]);

            $this->domainEventDispatcherService->dispatch(
                new OrderEvent(
                    type: DomainEventType::ORDER_REFUNDED,
                    orderId: $order->getId(),
                ),
            );

            return true;
        });

        if ($handled) {
            return;
        }

        throw new StripeLocalPaymentNotFoundException;
    }

    private function reconciliation(
        Refund $refund,
        ?string $stripeAccountId,
        string $eventId,
        string $eventType,
        ?string $paymentIntentId,
        ?string $chargeId,
    ): StripeWebhookReconciliationDTO {
        return new StripeWebhookReconciliationDTO(
            eventId: $eventId,
            eventType: $eventType,
            stripeAccountId: $stripeAccountId,
            providerObjectType: 'refund',
            providerObjectId: $refund->id,
            paymentIntentId: $paymentIntentId,
            chargeId: $chargeId,
            refundId: $refund->id,
            orderId: null,
            stripePaymentId: null,
            reason: StripeWebhookReconciliationReason::LOCAL_PAYMENT_MISSING,
        );
    }

    private function paymentIntentId(Refund $refund): ?string
    {
        if (is_string($refund->payment_intent)) {
            return $refund->payment_intent;
        }

        return $refund->payment_intent?->id;
    }

    private function chargeId(Refund $refund): ?string
    {
        if (is_string($refund->charge)) {
            return $refund->charge;
        }

        return $refund->charge?->id;
    }

    private function linkRefundRequest(
        Refund $refund,
        int $orderId,
        int $stripePaymentId,
        string $paymentIntentId,
        ?string $stripeAccountId,
    ): void {
        $metadata = method_exists($refund->metadata, 'toArray')
            ? $refund->metadata->toArray()
            : (array) $refund->metadata;
        $requestId = $metadata['hie_refund_request_id'] ?? null;
        if (! is_string($requestId) || ! Str::isUuid($requestId)) {
            return;
        }

        $request = $this->refundRequestRepository->findByRequestId($requestId, true);
        if ($request === null) {
            return;
        }

        if ($request->orderId !== $orderId
            || $request->stripePaymentId !== $stripePaymentId
            || $request->paymentIntentId !== $paymentIntentId
            || $request->stripeAccountId !== $stripeAccountId) {
            throw new \HiEvents\Exceptions\Stripe\StripeRefundRequestConflictException(
                __('The provider refund request identity does not match the local payment.')
            );
        }

        $this->refundRequestRepository->recordProviderResult(
            requestId: $requestId,
            providerRefundId: (string) $refund->id,
            providerStatus: (string) $refund->status,
            terminal: true,
        );
    }

    private function amountAsFloat(int $amount, string $currency): float
    {
        return Money::ofMinor($amount, $currency)->getAmount()->toFloat();
    }

    private function updateEventStatistics(OrderDomainObject $order, MoneyValue $amount): void
    {
        $this->eventStatisticsRefundService->updateForRefund($order, $amount);
    }

    private function updateOrderRefundedAmount(int $orderId, float $refundedAmount): void
    {
        $this->orderRepository->increment(
            id: $orderId,
            column: OrderDomainObjectAbstract::TOTAL_REFUNDED,
            amount: $refundedAmount,
        );
    }

    private function updateOrderStatus(OrderDomainObject $order, float $refundedAmount): void
    {
        $status = $refundedAmount + $order->getTotalRefunded() >= $order->getTotalGross()
            ? OrderRefundStatus::REFUNDED->name
            : OrderRefundStatus::PARTIALLY_REFUNDED->name;

        $this->orderRepository->updateFromArray($order->getId(), [
            OrderDomainObjectAbstract::REFUND_STATUS => $status,
        ]);
    }

    private function handleFailure(Refund $refund, OrderDomainObject $order, ?string $paymentIntentId): void
    {
        $this->orderRepository->updateFromArray($order->getId(), [
            OrderDomainObjectAbstract::REFUND_STATUS => OrderRefundStatus::REFUND_FAILED->name,
        ]);

        $this->logger->error(__('Failed to refund stripe charge'), [
            'refund_id' => $refund->id,
            'payment_intent_id' => $paymentIntentId,
            'refund_status' => $refund->status,
            'order_id' => $order->getId(),
        ]);
    }

    private function createOrderRefund(
        Refund $refund,
        OrderDomainObject $order,
        float $refundedAmount,
        ?string $paymentIntentId,
    ): void {
        $this->orderRefundRepository->create([
            'order_id' => $order->getId(),
            'payment_provider' => PaymentProviders::STRIPE->value,
            'refund_id' => $refund->id,
            'amount' => $refundedAmount,
            'currency' => $order->getCurrency(),
            'status' => $refund->status,
            'metadata' => [
                'payment_intent' => $paymentIntentId,
            ],
        ]);
    }
}
