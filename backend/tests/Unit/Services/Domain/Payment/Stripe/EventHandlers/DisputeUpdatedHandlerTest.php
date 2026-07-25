<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Payment\Stripe\EventHandlers;

use HiEvents\DomainObjects\Generated\StripePaymentDomainObjectAbstract;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Repository\Interfaces\StripeDisputeRepositoryInterface;
use HiEvents\Repository\Interfaces\StripePaymentsRepositoryInterface;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\StripeDisputeDTO;
use HiEvents\Services\Domain\Payment\Stripe\EventHandlers\DisputeUpdatedHandler;
use HiEvents\Services\Domain\Payment\Stripe\StripeProviderObjectLockService;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Mockery\MockInterface;
use Psr\Log\LoggerInterface;
use Stripe\Dispute;
use Tests\TestCase;
use UnexpectedValueException;

class DisputeUpdatedHandlerTest extends TestCase
{
    private StripePaymentsRepositoryInterface|MockInterface $payments;

    private StripeDisputeRepositoryInterface|MockInterface $disputes;

    private LoggerInterface|MockInterface $logger;

    private DatabaseManager|MockInterface $database;

    private StripeProviderObjectLockService|MockInterface $providerObjectLock;

    private DisputeUpdatedHandler $handler;

    protected function setUp(): void
    {
        parent::setUp();

        $this->payments = Mockery::mock(StripePaymentsRepositoryInterface::class);
        $this->disputes = Mockery::mock(StripeDisputeRepositoryInterface::class);
        $this->logger = Mockery::mock(LoggerInterface::class);
        $this->database = Mockery::mock(DatabaseManager::class);
        $this->providerObjectLock = Mockery::mock(StripeProviderObjectLockService::class);
        $this->handler = new DisputeUpdatedHandler(
            $this->payments,
            $this->disputes,
            $this->logger,
            $this->database,
            $this->providerObjectLock,
        );
    }

    public function test_stores_created_dispute_against_existing_order(): void
    {
        $payment = (new StripePaymentDomainObject)
            ->setId(31)
            ->setOrderId(10)
            ->setPaymentIntentId('pi_test_disputed')
            ->setChargeId('ch_test_disputed');

        $this->database->shouldReceive('transaction')->once()->andReturnUsing(static fn ($callback) => $callback());
        $this->providerObjectLock->shouldReceive('acquirePaymentIdentity')
            ->once()
            ->with('pi_test_disputed', 'ch_test_disputed');
        $this->payments->shouldReceive('findFirstWhere')
            ->once()
            ->with([
                StripePaymentDomainObjectAbstract::PAYMENT_INTENT_ID => 'pi_test_disputed',
                StripePaymentDomainObjectAbstract::CONNECTED_ACCOUNT_ID => 'acct_test_connected',
            ])
            ->andReturn($payment);

        $this->disputes->shouldReceive('upsert')
            ->once()
            ->with(Mockery::on(static function (StripeDisputeDTO $dto): bool {
                return $dto->disputeId === 'du_test_created'
                    && $dto->orderId === 10
                    && $dto->stripePaymentId === 31
                    && $dto->paymentIntentId === 'pi_test_disputed'
                    && $dto->chargeId === 'ch_test_disputed'
                    && $dto->stripeAccountId === 'acct_test_connected'
                    && $dto->amountMinor === 5695
                    && $dto->currency === 'usd'
                    && $dto->status === 'needs_response'
                    && $dto->reason === 'fraudulent'
                    && $dto->balanceTransactionIds === ['txn_test_dispute']
                    && $dto->evidenceDueAt?->getTimestamp() === 1_800_000_000
                    && $dto->providerCreatedAt?->getTimestamp() === 1_700_000_000
                    && $dto->closedAt === null
                    && $dto->lastEventId === 'evt_test_created'
                    && $dto->lastEventType === 'charge.dispute.created'
                    && $dto->lastEventCreatedAt->getTimestamp() === 1_700_000_100;
            }));

        $this->logger->shouldReceive('info')
            ->once()
            ->with('Stripe dispute lifecycle state stored', [
                'dispute_id' => 'du_test_created',
                'status' => 'needs_response',
                'order_id' => 10,
                'stripe_account_id' => 'acct_test_connected',
                'event_id' => 'evt_test_created',
            ]);

        $this->handler->handleEvent(
            $this->dispute(),
            'acct_test_connected',
            'evt_test_created',
            'charge.dispute.created',
            1_700_000_100,
        );
    }

    public function test_closed_dispute_persists_even_when_payment_cannot_be_joined(): void
    {
        $dispute = $this->dispute([
            'status' => 'won',
            'payment_intent' => null,
        ]);

        $this->database->shouldReceive('transaction')->once()->andReturnUsing(static fn ($callback) => $callback());
        $this->providerObjectLock->shouldReceive('acquirePaymentIdentity')
            ->once()
            ->with(null, 'ch_test_disputed');
        $this->payments->shouldReceive('findFirstWhere')
            ->once()
            ->with([
                StripePaymentDomainObjectAbstract::CHARGE_ID => 'ch_test_disputed',
                StripePaymentDomainObjectAbstract::CONNECTED_ACCOUNT_ID => null,
            ])
            ->andReturnNull();

        $this->disputes->shouldReceive('upsert')
            ->once()
            ->with(Mockery::on(static fn (StripeDisputeDTO $dto): bool => $dto->status === 'won'
                && $dto->orderId === null
                && $dto->stripePaymentId === null
                && $dto->closedAt?->getTimestamp() === 1_700_000_200
                && $dto->lastEventType === 'charge.dispute.closed'
            ));

        $this->logger->shouldReceive('info')->once();

        $this->handler->handleEvent(
            $dispute,
            null,
            'evt_test_closed',
            'charge.dispute.closed',
            1_700_000_200,
        );
    }

    public function test_rejects_malformed_dispute_before_persistence(): void
    {
        $dispute = $this->dispute(['amount' => -1]);

        $this->database->shouldReceive('transaction')->never();
        $this->providerObjectLock->shouldReceive('acquirePaymentIdentity')->never();
        $this->payments->shouldReceive('findFirstWhere')->never();
        $this->disputes->shouldReceive('upsert')->never();
        $this->logger->shouldReceive('info')->never();

        $this->expectException(UnexpectedValueException::class);
        $this->handler->handleEvent(
            $dispute,
            'acct_test_connected',
            'evt_test_invalid',
            'charge.dispute.updated',
            1_700_000_300,
        );
    }

    private function dispute(array $overrides = []): Dispute
    {
        return Dispute::constructFrom(array_replace_recursive([
            'id' => 'du_test_created',
            'object' => 'dispute',
            'amount' => 5695,
            'charge' => 'ch_test_disputed',
            'balance_transactions' => [
                ['id' => 'txn_test_dispute', 'object' => 'balance_transaction'],
            ],
            'created' => 1_700_000_000,
            'currency' => 'usd',
            'evidence_details' => [
                'due_by' => 1_800_000_000,
            ],
            'payment_intent' => 'pi_test_disputed',
            'reason' => 'fraudulent',
            'status' => 'needs_response',
        ], $overrides));
    }
}
