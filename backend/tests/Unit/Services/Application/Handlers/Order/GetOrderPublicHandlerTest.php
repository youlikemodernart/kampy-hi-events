<?php

namespace Tests\Unit\Services\Application\Handlers\Order;

use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderStatus;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\DTO\GetOrderPublicDTO;
use HiEvents\Services\Application\Handlers\Order\GetOrderPublicHandler;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use Mockery;
use Mockery\MockInterface;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Tests\TestCase;

class GetOrderPublicHandlerTest extends TestCase
{
    private OrderRepositoryInterface|MockInterface $orderRepository;
    private CheckoutSessionManagementService|MockInterface $sessionManagementService;
    private GetOrderPublicHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->sessionManagementService = Mockery::mock(CheckoutSessionManagementService::class);

        $this->handler = new GetOrderPublicHandler(
            $this->orderRepository,
            $this->sessionManagementService,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testHandleThrowsNotFoundWhenRouteEventDoesNotMatchOrder(): void
    {
        $order = (new OrderDomainObject())
            ->setEventId(99)
            ->setStatus(OrderStatus::RESERVED->name)
            ->setSessionId('checkout-session');

        $this->orderRepository->shouldReceive('loadRelation')->times(3)->andReturnSelf();
        $this->orderRepository->shouldReceive('findByShortId')
            ->once()
            ->with('ABC123')
            ->andReturn($order);

        $this->sessionManagementService->shouldNotReceive('verifySession');

        $this->expectException(ResourceNotFoundException::class);

        $this->handler->handle(new GetOrderPublicDTO(
            eventId: 2,
            orderShortId: 'ABC123',
        ));
    }
}
