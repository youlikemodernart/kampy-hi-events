<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Application\Handlers\Order;

use HiEvents\DomainObjects\Enums\PaymentProviders;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderRefundStatus;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\CancelOrderHandler;
use HiEvents\Services\Application\Handlers\Order\DTO\CancelOrderDTO;
use HiEvents\Services\Application\Handlers\Order\DTO\RefundOrderDTO;
use HiEvents\Services\Application\Handlers\Order\Payment\Stripe\RefundOrderHandler;
use HiEvents\Services\Domain\Order\OrderCancelService;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Ramsey\Uuid\Uuid;
use Tests\TestCase;

class CancelOrderHandlerTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_cancel_with_refund_delegates_with_stable_identity_outside_an_outer_transaction(): void
    {
        $order = (new OrderDomainObject)
            ->setId(10)
            ->setEventId(3)
            ->setPublicId('ord_public_fixture')
            ->setTotalGross(50.00)
            ->setTotalRefunded(10.00)
            ->setCurrency('USD')
            ->setStatus('COMPLETED')
            ->setPaymentProvider(PaymentProviders::STRIPE->name)
            ->setRefundStatus(OrderRefundStatus::PARTIALLY_REFUNDED->name);
        $orders = Mockery::mock(OrderRepositoryInterface::class);
        $orders->shouldReceive('findFirstWhere')->once()->andReturn($order);
        $database = Mockery::mock(DatabaseManager::class);
        $database->shouldNotReceive('transaction');
        $refunds = Mockery::mock(RefundOrderHandler::class);
        $expectedRequestId = Uuid::uuid5(
            Uuid::NAMESPACE_URL,
            'hie:cancel-order-refund:v1:ord_public_fixture',
        )->toString();
        $refunds->shouldReceive('handle')
            ->once()
            ->withArgs(static function (RefundOrderDTO $dto) use ($expectedRequestId): bool {
                return $dto->refund_request_id === $expectedRequestId
                    && $dto->event_id === 3
                    && $dto->order_id === 10
                    && $dto->amount === 40.00
                    && $dto->notify_buyer
                    && $dto->cancel_order;
            })
            ->andReturn($order);

        $result = (new CancelOrderHandler(
            Mockery::mock(OrderCancelService::class),
            $orders,
            $database,
            $refunds,
        ))->handle(new CancelOrderDTO(eventId: 3, orderId: 10, refund: true));

        self::assertSame($order, $result);
    }
}
