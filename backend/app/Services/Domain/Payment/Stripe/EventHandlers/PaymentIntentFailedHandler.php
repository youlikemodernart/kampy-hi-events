<?php

namespace HiEvents\Services\Domain\Payment\Stripe\EventHandlers;

use HiEvents\DomainObjects\Enums\StripeWebhookReconciliationReason;
use HiEvents\DomainObjects\Enums\StripeWebhookReconciliationStatus;
use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\Generated\StripePaymentDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Events\OrderStatusChangedEvent;
use HiEvents\Exceptions\Stripe\StripeLocalPaymentNotFoundException;
use HiEvents\Repository\Eloquent\StripePaymentsRepository;
use HiEvents\Repository\Eloquent\Value\Relationship;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\StripeWebhookReconciliationRepositoryInterface;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\StripeWebhookReconciliationDTO;
use HiEvents\Services\Domain\Payment\Stripe\StripePaymentUpdateFromPaymentIntentService;
use HiEvents\Services\Domain\Payment\Stripe\StripeProviderObjectLockService;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Stripe\PaymentIntent;
use Throwable;

readonly class PaymentIntentFailedHandler
{
    public function __construct(
        private OrderRepositoryInterface $orderRepository,
        private StripePaymentsRepository $stripePaymentsRepository,
        private DatabaseManager $databaseManager,
        private StripePaymentUpdateFromPaymentIntentService $stripePaymentUpdateFromPaymentIntentService,
        private StripeWebhookReconciliationRepositoryInterface $reconciliationRepository,
        private StripeProviderObjectLockService $providerObjectLockService,
        private LoggerInterface $logger,
    ) {}

    /**
     * @throws Throwable
     */
    public function handleEvent(
        PaymentIntent $paymentIntent,
        ?string $stripeAccountId,
        string $eventId,
        string $eventType,
    ): void {
        $chargeId = $this->chargeId($paymentIntent);
        $pendingReconciliation = $this->reconciliation(
            paymentIntent: $paymentIntent,
            stripeAccountId: $stripeAccountId,
            eventId: $eventId,
            eventType: $eventType,
            chargeId: $chargeId,
        );

        $handled = $this->databaseManager->transaction(function () use (
            $paymentIntent,
            $chargeId,
            $pendingReconciliation,
        ): bool {
            $this->providerObjectLockService->acquirePaymentIdentity($paymentIntent->id, $chargeId);

            /** @var StripePaymentDomainObjectAbstract|null $stripePayment */
            $stripePayment = $this->stripePaymentsRepository
                ->loadRelation(new Relationship(OrderDomainObject::class, name: 'order'))
                ->findFirstWhere([
                    StripePaymentDomainObjectAbstract::PAYMENT_INTENT_ID => $paymentIntent->id,
                ]);

            if (! $stripePayment) {
                $exception = new StripeLocalPaymentNotFoundException;
                $this->reconciliationRepository->recordPending($pendingReconciliation, $exception::class);

                return false;
            }

            $order = $stripePayment->getOrder();
            $reconciliation = new StripeWebhookReconciliationDTO(
                eventId: $pendingReconciliation->eventId,
                eventType: $pendingReconciliation->eventType,
                stripeAccountId: $pendingReconciliation->stripeAccountId,
                providerObjectType: $pendingReconciliation->providerObjectType,
                providerObjectId: $pendingReconciliation->providerObjectId,
                paymentIntentId: $pendingReconciliation->paymentIntentId,
                chargeId: $pendingReconciliation->chargeId,
                refundId: null,
                orderId: $stripePayment->getOrderId(),
                stripePaymentId: $stripePayment->getId(),
                reason: StripeWebhookReconciliationReason::LOCAL_PAYMENT_MISSING,
            );

            if ($order?->getPaymentStatus() === OrderPaymentStatus::PAYMENT_RECEIVED->name) {
                $isConsistentTerminal = $order->getStatus() === OrderStatus::COMPLETED->name;
                $audit = new StripeWebhookReconciliationDTO(
                    eventId: $reconciliation->eventId,
                    eventType: $reconciliation->eventType,
                    stripeAccountId: $reconciliation->stripeAccountId,
                    providerObjectType: $reconciliation->providerObjectType,
                    providerObjectId: $reconciliation->providerObjectId,
                    paymentIntentId: $reconciliation->paymentIntentId,
                    chargeId: $reconciliation->chargeId,
                    refundId: null,
                    orderId: $reconciliation->orderId,
                    stripePaymentId: $reconciliation->stripePaymentId,
                    reason: $isConsistentTerminal
                        ? StripeWebhookReconciliationReason::PAID_TERMINAL_FAILURE_IGNORED
                        : StripeWebhookReconciliationReason::PAID_STATE_INCONSISTENT,
                );
                $this->reconciliationRepository->recordAudit(
                    $audit,
                    $isConsistentTerminal
                        ? StripeWebhookReconciliationStatus::RESOLVED
                        : StripeWebhookReconciliationStatus::MANUAL_REVIEW,
                );
                $this->logger->warning('Ignored Stripe payment failure for an order with received payment', [
                    'event_id' => $audit->eventId,
                    'payment_intent_id' => $paymentIntent->id,
                    'order_id' => $stripePayment->getOrderId(),
                    'order_status' => $order->getStatus(),
                    'reconciliation_status' => $isConsistentTerminal
                        ? StripeWebhookReconciliationStatus::RESOLVED->value
                        : StripeWebhookReconciliationStatus::MANUAL_REVIEW->value,
                ]);

                return true;
            }

            $this->reconciliationRepository->resolveExisting($reconciliation);
            $this->stripePaymentUpdateFromPaymentIntentService->updateStripePaymentInfo($paymentIntent, $stripePayment);

            $updatedOrder = $this->updateOrderStatuses($stripePayment);
            OrderStatusChangedEvent::dispatch($updatedOrder);

            return true;
        });

        if ($handled) {
            return;
        }

        throw new StripeLocalPaymentNotFoundException;
    }

    private function reconciliation(
        PaymentIntent $paymentIntent,
        ?string $stripeAccountId,
        string $eventId,
        string $eventType,
        ?string $chargeId,
    ): StripeWebhookReconciliationDTO {
        return new StripeWebhookReconciliationDTO(
            eventId: $eventId,
            eventType: $eventType,
            stripeAccountId: $stripeAccountId,
            providerObjectType: 'payment_intent',
            providerObjectId: $paymentIntent->id,
            paymentIntentId: $paymentIntent->id,
            chargeId: $chargeId,
            refundId: null,
            orderId: null,
            stripePaymentId: null,
            reason: StripeWebhookReconciliationReason::LOCAL_PAYMENT_MISSING,
        );
    }

    private function chargeId(PaymentIntent $paymentIntent): ?string
    {
        if (is_string($paymentIntent->latest_charge)) {
            return $paymentIntent->latest_charge;
        }

        return $paymentIntent->latest_charge?->id;
    }

    private function updateOrderStatuses(StripePaymentDomainObjectAbstract $stripePayment): OrderDomainObject
    {
        return $this->orderRepository
            ->loadRelation(OrderItemDomainObject::class)
            ->updateFromArray($stripePayment->getOrderId(), [
                OrderDomainObjectAbstract::PAYMENT_STATUS => OrderPaymentStatus::PAYMENT_FAILED->name,
            ]);
    }
}
