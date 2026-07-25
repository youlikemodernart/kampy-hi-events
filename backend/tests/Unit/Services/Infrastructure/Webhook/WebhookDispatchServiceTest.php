<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Infrastructure\Webhook;

use HiEvents\Repository\Interfaces\AttendeeCheckInRepositoryInterface;
use HiEvents\Repository\Interfaces\AttendeeRepositoryInterface;
use HiEvents\Repository\Interfaces\EventRepositoryInterface;
use HiEvents\Repository\Interfaces\OrderRepositoryInterface;
use HiEvents\Repository\Interfaces\ProductRepositoryInterface;
use HiEvents\Repository\Interfaces\WebhookRepositoryInterface;
use HiEvents\Services\Infrastructure\Webhook\WebhookDispatchService;
use Mockery;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionMethod;
use Tests\TestCase;

class WebhookDispatchServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_legacy_order_dispatch_call_remains_source_compatible(): void
    {
        $method = new ReflectionMethod(WebhookDispatchService::class, 'dispatchOrderWebhook');
        $deliveryParameter = $method->getParameters()[2];

        self::assertTrue($deliveryParameter->isOptional());
        self::assertNull($deliveryParameter->getDefaultValue());
    }

    public function test_child_delivery_ids_are_deterministic_and_resource_scoped(): void
    {
        $service = new WebhookDispatchService(
            Mockery::mock(LoggerInterface::class),
            Mockery::mock(WebhookRepositoryInterface::class),
            Mockery::mock(OrderRepositoryInterface::class),
            Mockery::mock(ProductRepositoryInterface::class),
            Mockery::mock(AttendeeRepositoryInterface::class),
            Mockery::mock(AttendeeCheckInRepositoryInterface::class),
            Mockery::mock(EventRepositoryInterface::class),
        );
        $method = (new ReflectionClass($service))->getMethod('childDeliveryId');

        $first = $method->invoke($service, 'oef_parent', 'attendee', 10);
        $retry = $method->invoke($service, 'oef_parent', 'attendee', 10);
        $differentResource = $method->invoke($service, 'oef_parent', 'order', 10);
        $differentId = $method->invoke($service, 'oef_parent', 'attendee', 11);

        self::assertSame($first, $retry);
        self::assertMatchesRegularExpression('/^oef_[a-f0-9]{40}$/', $first);
        self::assertNotSame($first, $differentResource);
        self::assertNotSame($first, $differentId);
    }
}
