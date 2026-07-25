<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\Stripe;

use Illuminate\Database\DatabaseManager;
use InvalidArgumentException;
use LogicException;

class StripeProviderObjectLockService
{
    public function __construct(
        private readonly DatabaseManager $databaseManager,
    ) {}

    public function acquirePaymentIdentity(?string $paymentIntentId, ?string $chargeId = null): void
    {
        if ($paymentIntentId === null && $chargeId === null) {
            throw new InvalidArgumentException('A Stripe payment identity is required for locking.');
        }

        $identities = array_filter([
            $paymentIntentId === null ? null : 'payment_intent:'.$paymentIntentId,
            $chargeId === null ? null : 'charge:'.$chargeId,
        ]);
        sort($identities, SORT_STRING);
        $connection = $this->databaseManager->connection();

        if ($connection->getDriverName() !== 'pgsql') {
            return;
        }

        if ($connection->transactionLevel() < 1) {
            throw new LogicException('Stripe provider object locks require an active database transaction.');
        }

        foreach ($identities as $identity) {
            $connection->selectOne(
                'SELECT pg_advisory_xact_lock(hashtextextended(CAST(? AS text), 0))',
                ['stripe:'.$identity],
            );
        }
    }
}
