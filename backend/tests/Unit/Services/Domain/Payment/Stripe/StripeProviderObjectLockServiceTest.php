<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Payment\Stripe;

use HiEvents\Services\Domain\Payment\Stripe\StripeProviderObjectLockService;
use Illuminate\Database\Connection;
use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use LogicException;
use Mockery;
use Tests\TestCase;

class StripeProviderObjectLockServiceTest extends TestCase
{
    public function test_non_postgres_connections_need_no_advisory_lock(): void
    {
        $database = Mockery::mock(DatabaseManager::class);
        $connection = Mockery::mock(Connection::class);
        $database->shouldReceive('connection')->once()->andReturn($connection);
        $connection->shouldReceive('getDriverName')->once()->andReturn('sqlite');
        $connection->shouldNotReceive('transactionLevel');
        $connection->shouldNotReceive('selectOne');

        (new StripeProviderObjectLockService($database))->acquirePaymentIdentity('pi_test');

        self::assertTrue(true);
    }

    public function test_postgres_lock_requires_an_active_transaction(): void
    {
        $database = Mockery::mock(DatabaseManager::class);
        $connection = Mockery::mock(Connection::class);
        $database->shouldReceive('connection')->once()->andReturn($connection);
        $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
        $connection->shouldReceive('transactionLevel')->once()->andReturn(0);
        $connection->shouldNotReceive('selectOne');

        $this->expectException(LogicException::class);

        (new StripeProviderObjectLockService($database))->acquirePaymentIdentity('pi_test');
    }

    public function test_postgres_transaction_uses_a_stable_payment_identity_lock(): void
    {
        $database = Mockery::mock(DatabaseManager::class);
        $connection = Mockery::mock(Connection::class);
        $database->shouldReceive('connection')->once()->andReturn($connection);
        $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
        $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
        $connection->shouldReceive('selectOne')
            ->once()
            ->with(
                'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
                ['stripe:payment_intent:pi_test'],
            );

        (new StripeProviderObjectLockService($database))->acquirePaymentIdentity('pi_test');

        self::assertTrue(true);
    }

    public function test_postgres_locks_charge_and_payment_intent_in_deterministic_order(): void
    {
        $database = Mockery::mock(DatabaseManager::class);
        $connection = Mockery::mock(Connection::class);
        $database->shouldReceive('connection')->once()->andReturn($connection);
        $connection->shouldReceive('getDriverName')->once()->andReturn('pgsql');
        $connection->shouldReceive('transactionLevel')->once()->andReturn(1);
        $connection->shouldReceive('selectOne')
            ->once()
            ->ordered()
            ->with(
                'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
                ['stripe:charge:ch_test'],
            );
        $connection->shouldReceive('selectOne')
            ->once()
            ->ordered()
            ->with(
                'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
                ['stripe:payment_intent:pi_test'],
            );

        (new StripeProviderObjectLockService($database))->acquirePaymentIdentity('pi_test', 'ch_test');

        self::assertTrue(true);
    }

    public function test_missing_payment_identity_is_rejected(): void
    {
        $database = Mockery::mock(DatabaseManager::class);

        $this->expectException(InvalidArgumentException::class);

        (new StripeProviderObjectLockService($database))->acquirePaymentIdentity(null, null);
    }
}
