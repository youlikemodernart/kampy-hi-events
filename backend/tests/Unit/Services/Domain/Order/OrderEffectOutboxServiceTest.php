<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Order;

use HiEvents\DomainObjects\Enums\OrderEffectEmailKind;
use HiEvents\DomainObjects\Enums\OrderEffectType;
use HiEvents\Exceptions\ResourceConflictException;
use HiEvents\Repository\Interfaces\OrderEffectOutboxRepositoryInterface;
use HiEvents\Services\Domain\Order\DTOs\OrderEffectRequestDTO;
use HiEvents\Services\Domain\Order\OrderEffectOutboxService;
use HiEvents\Services\Infrastructure\DomainEvents\Enums\DomainEventType;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Tests\TestCase;

class OrderEffectOutboxServiceTest extends TestCase
{
    public function test_disabled_bridge_does_not_add_registration_outbox_noise(): void
    {
        $repository = Mockery::mock(OrderEffectOutboxRepositoryInterface::class);
        $database = Mockery::mock(DatabaseManager::class);
        $connection = Mockery::mock(Connection::class);
        $database->shouldReceive('connection')->once()->andReturn($connection);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
        $repository->shouldReceive('enqueue')->once()->with(
            10,
            OrderEffectOutboxService::TRANSITION_STRIPE_COMPLETED,
            Mockery::on(static fn (OrderEffectRequestDTO $effect): bool => $effect->effectType === OrderEffectType::STATISTICS),
        );
        $repository->shouldReceive('enqueue')->once()->with(
            10,
            OrderEffectOutboxService::TRANSITION_STRIPE_COMPLETED,
            Mockery::on(static fn (OrderEffectRequestDTO $effect): bool => $effect->effectType === OrderEffectType::EMAIL
                && $effect->emailKind === OrderEffectEmailKind::DETAILS_AND_TICKETS),
        );
        $repository->shouldReceive('enqueue')->once()->with(
            10,
            OrderEffectOutboxService::TRANSITION_STRIPE_COMPLETED,
            Mockery::on(static fn (OrderEffectRequestDTO $effect): bool => $effect->effectType === OrderEffectType::WEBHOOK
                && $effect->domainEventType === DomainEventType::ORDER_CREATED),
        );
        config()->set('services.gvsu_registration_bridge.mode', 'disabled');
        (new OrderEffectOutboxService($repository, $database))->enqueueCompletedOrder(
            10,
            7,
            OrderEffectOutboxService::TRANSITION_STRIPE_COMPLETED,
            DomainEventType::ORDER_CREATED,
        );

        self::assertTrue(true);
    }

    public function test_non_gvsu_completed_order_does_not_add_bridge_outbox_noise_when_enabled(): void
    {
        config()->set('services.gvsu_registration_bridge.mode', 'live');
        $repository = Mockery::mock(OrderEffectOutboxRepositoryInterface::class);
        $database = Mockery::mock(DatabaseManager::class);
        $connection = Mockery::mock(Connection::class);
        $database->shouldReceive('connection')->once()->andReturn($connection);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
        $repository->shouldReceive('enqueue')->times(3);

        (new OrderEffectOutboxService($repository, $database))->enqueueCompletedOrder(
            10,
            8,
            OrderEffectOutboxService::TRANSITION_STRIPE_COMPLETED,
            DomainEventType::ORDER_CREATED,
        );
    }

    public function test_enabled_event_seven_completed_order_enqueues_bridge_effect(): void
    {
        config()->set('services.gvsu_registration_bridge.mode', 'live');
        $repository = Mockery::mock(OrderEffectOutboxRepositoryInterface::class);
        $database = Mockery::mock(DatabaseManager::class);
        $connection = Mockery::mock(Connection::class);
        $database->shouldReceive('connection')->once()->andReturn($connection);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
        $repository->shouldReceive('enqueue')->times(3);
        $repository->shouldReceive('enqueue')->once()->with(
            10,
            OrderEffectOutboxService::TRANSITION_STRIPE_COMPLETED,
            Mockery::on(static fn (OrderEffectRequestDTO $effect): bool => $effect->effectType === OrderEffectType::GVSU_REGISTRATION_BRIDGE),
        );

        (new OrderEffectOutboxService($repository, $database))->enqueueCompletedOrder(
            10,
            7,
            OrderEffectOutboxService::TRANSITION_STRIPE_COMPLETED,
            DomainEventType::ORDER_CREATED,
        );
    }

    public function test_offline_submission_records_only_details_email_and_order_created_webhook(): void
    {
        $repository = Mockery::mock(OrderEffectOutboxRepositoryInterface::class);
        $database = Mockery::mock(DatabaseManager::class);
        $connection = Mockery::mock(Connection::class);
        $database->shouldReceive('connection')->once()->andReturn($connection);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
        $repository->shouldReceive('enqueue')->once()->with(
            10,
            OrderEffectOutboxService::TRANSITION_OFFLINE_SUBMITTED,
            Mockery::on(static fn (OrderEffectRequestDTO $effect): bool => $effect->effectType === OrderEffectType::EMAIL
                && $effect->emailKind === OrderEffectEmailKind::DETAILS_AND_TICKETS
                && $effect->domainEventType === null),
        );
        $repository->shouldReceive('enqueue')->once()->with(
            10,
            OrderEffectOutboxService::TRANSITION_OFFLINE_SUBMITTED,
            Mockery::on(static fn (OrderEffectRequestDTO $effect): bool => $effect->effectType === OrderEffectType::WEBHOOK
                && $effect->domainEventType === DomainEventType::ORDER_CREATED
                && $effect->emailKind === null),
        );

        (new OrderEffectOutboxService($repository, $database))->enqueueOfflineSubmission(10);

        self::assertTrue(true);
    }

    public function test_effects_cannot_be_recorded_outside_the_owning_transaction(): void
    {
        $repository = Mockery::mock(OrderEffectOutboxRepositoryInterface::class);
        $database = Mockery::mock(DatabaseManager::class);
        $connection = Mockery::mock(Connection::class);
        $database->shouldReceive('connection')->once()->andReturn($connection);
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $repository->shouldNotReceive('enqueue');

        $this->expectException(ResourceConflictException::class);

        (new OrderEffectOutboxService($repository, $database))->enqueueOfflineSubmission(10);
    }
}
