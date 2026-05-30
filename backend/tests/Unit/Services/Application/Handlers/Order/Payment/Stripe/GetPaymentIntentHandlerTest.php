<?php

namespace Tests\Unit\Services\Application\Handlers\Order\Payment\Stripe;

use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\Status\OrderPaymentStatus;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Services\Application\Handlers\Order\Payment\Stripe\GetPaymentIntentHandler;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\PaymentIntentSucceededHandler;
use HiEvents\Services\Infrastructure\Session\CheckoutSessionManagementService;
use HiEvents\Services\Infrastructure\Stripe\StripeClientFactory;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class GetPaymentIntentHandlerTest extends TestCase
{
    private StripeClientFactory|MockInterface $stripeClientFactory;
    private OrderRepositoryInterface|MockInterface $orderRepository;
    private LoggerInterface|MockInterface $logger;
    private PaymentIntentSucceededHandler|MockInterface $paymentIntentSucceededHandler;
    private CheckoutSessionManagementService|MockInterface $sessionManagementService;
    private GetPaymentIntentHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->stripeClientFactory = Mockery::mock(StripeClientFactory::class);
        $this->orderRepository = Mockery::mock(OrderRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->paymentIntentSucceededHandler = Mockery::mock(PaymentIntentSucceededHandler::class);
        $this->sessionManagementService = Mockery::mock(CheckoutSessionManagementService::class);

        $this->handler = new GetPaymentIntentHandler(
            $this->stripeClientFactory,
            $this->orderRepository,
            $this->logger,
            $this->paymentIntentSucceededHandler,
            $this->sessionManagementService,
        );
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function testHandleRequiresCheckoutSessionBeforeReadingUnpaidPaymentIntent(): void
    {
        $order = (new OrderDomainObject())
            ->setId(1)
            ->setShortId('ABC123')
            ->setEventId(2)
            ->setSessionId('checkout-session')
            ->setPaymentStatus(OrderPaymentStatus::AWAITING_PAYMENT->name)
            ->setStripePayment(
                (new StripePaymentDomainObject())
                    ->setPaymentIntentId('pi_test')
            );

        $this->orderRepository->shouldReceive('loadRelation')->once()->andReturnSelf();
        $this->orderRepository->shouldReceive('findFirstWhere')
            ->once()
            ->with([
                'event_id' => 2,
                'short_id' => 'ABC123',
            ])
            ->andReturn($order);

        $this->sessionManagementService->shouldReceive('verifySession')
            ->once()
            ->with('checkout-session')
            ->andReturn(false);

        $this->stripeClientFactory->shouldNotReceive('createForPlatform');
        $this->paymentIntentSucceededHandler->shouldNotReceive('handleEvent');

        $this->expectException(UnauthorizedException::class);

        $this->handler->handle(2, 'ABC123');
    }
}
