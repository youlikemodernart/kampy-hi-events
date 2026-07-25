<?php

namespace HiEvents\Services\Application\Handlers\Order;

use HiEvents\DomainObjects\Generated\OrderDomainObjectAbstract;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\CancelOrderDTO;
use HiEvents\Services\Application\Handlers\Order\DTO\RefundOrderDTO;
use HiEvents\Services\Application\Handlers\Order\Payment\Stripe\RefundOrderHandler;
use HiEvents\Services\Domain\Order\OrderCancelService;
use Illuminate\Database\DatabaseManager;
use Ramsey\Uuid\Uuid;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Throwable;

class CancelOrderHandler
{
    public function __construct(
        private readonly OrderCancelService $orderCancelService,
        private readonly OrderRepositoryInterface $orderRepository,
        private readonly DatabaseManager $databaseManager,
        private readonly RefundOrderHandler $refundOrderHandler,
    ) {}

    /**
     * @throws Throwable
     * @throws ResourceConflictException
     */
    public function handle(CancelOrderDTO $cancelOrderDTO): OrderDomainObject
    {
        $order = $this->findOrder($cancelOrderDTO);
        if ($cancelOrderDTO->refund && $order->isRefundable()) {
            return $this->refundOrderHandler->handle(new RefundOrderDTO(
                refund_request_id: Uuid::uuid5(
                    Uuid::NAMESPACE_URL,
                    'hie:cancel-order-refund:v1:'.$order->getPublicId(),
                )->toString(),
                event_id: $cancelOrderDTO->eventId,
                order_id: $cancelOrderDTO->orderId,
                amount: $order->getTotalGross() - $order->getTotalRefunded(),
                notify_buyer: true,
                cancel_order: true,
            ));
        }

        return $this->databaseManager->transaction(function () use ($cancelOrderDTO): OrderDomainObject {
            $order = $this->findOrder($cancelOrderDTO);
            if ($order->isOrderCancelled()) {
                throw new ResourceConflictException(__('Order already cancelled'));
            }

            $this->orderCancelService->cancelOrder($order);

            return $this->orderRepository->findById($order->getId());
        });
    }

    private function findOrder(CancelOrderDTO $cancelOrderDTO): OrderDomainObject
    {
        $order = $this->orderRepository->findFirstWhere([
            OrderDomainObjectAbstract::EVENT_ID => $cancelOrderDTO->eventId,
            OrderDomainObjectAbstract::ID => $cancelOrderDTO->orderId,
        ]);
        if ($order === null) {
            throw new ResourceNotFoundException(__('Order not found'));
        }

        return $order;
    }
}
