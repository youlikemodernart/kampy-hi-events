<?php

declare(strict_types=1);

namespace HiEvents\Services\Domain\Payment\Stripe\EventHandlers;

use DateTimeImmutable;
use HiEvents\DomainObjects\Generated\StripePaymentDomainObjectAbstract;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Repository\Interfaces\StripeDisputeRepositoryInterface;
use HiEvents\Repository\Interfaces\StripePaymentsRepositoryInterface;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\StripeDisputeDTO;
use HiEvents\Services\Domain\Payment\Stripe\StripeProviderObjectLockService;
use Illuminate\Database\DatabaseManager;
use Psr\Log\LoggerInterface;
use Stripe\Dispute;
use UnexpectedValueException;

class DisputeUpdatedHandler
{
    private const CLOSED_STATUSES = ['won', 'lost', 'warning_closed'];

    public function __construct(
        private readonly StripePaymentsRepositoryInterface $stripePaymentsRepository,
        private readonly StripeDisputeRepositoryInterface $stripeDisputeRepository,
        private readonly LoggerInterface $logger,
        private readonly DatabaseManager $databaseManager,
        private readonly StripeProviderObjectLockService $providerObjectLockService,
    ) {}

    public function handleEvent(
        Dispute $dispute,
        ?string $stripeAccountId,
        string $eventId,
        string $eventType,
        int $eventCreated,
    ): void {
        $disputeId = $this->requiredString($dispute->id ?? null, 'dispute id');
        $status = strtolower($this->requiredString($dispute->status ?? null, 'dispute status'));
        $currency = strtolower($this->requiredString($dispute->currency ?? null, 'dispute currency'));
        $amountMinor = $dispute->amount ?? null;

        if (! is_int($amountMinor) || $amountMinor < 0) {
            throw new UnexpectedValueException('Stripe dispute amount must be a non-negative integer.');
        }

        $paymentIntentId = $this->expandableId($dispute->payment_intent ?? null);
        $chargeId = $this->expandableId($dispute->charge ?? null);
        $eventCreatedAt = $this->requiredTimestamp($eventCreated, 'event created');
        $closedAt = in_array($status, self::CLOSED_STATUSES, true) ? $eventCreatedAt : null;

        $this->databaseManager->transaction(function () use (
            $paymentIntentId,
            $chargeId,
            $disputeId,
            $stripeAccountId,
            $amountMinor,
            $currency,
            $status,
            $dispute,
            $closedAt,
            $eventId,
            $eventType,
            $eventCreatedAt,
        ): void {
            $this->providerObjectLockService->acquirePaymentIdentity($paymentIntentId, $chargeId);
            $stripePayment = $this->findStripePayment($paymentIntentId, $chargeId, $stripeAccountId);

            $this->stripeDisputeRepository->upsert(new StripeDisputeDTO(
                disputeId: $disputeId,
                orderId: $stripePayment?->getOrderId(),
                stripePaymentId: $stripePayment?->getId(),
                paymentIntentId: $paymentIntentId,
                chargeId: $chargeId,
                stripeAccountId: $stripeAccountId,
                amountMinor: $amountMinor,
                currency: $currency,
                status: $status,
                reason: $this->optionalString($dispute->reason ?? null),
                balanceTransactionIds: $this->expandableIds($dispute->balance_transactions ?? null),
                evidenceDueAt: $this->timestamp($dispute->evidence_details->due_by ?? null),
                closedAt: $closedAt,
                providerCreatedAt: $this->timestamp($dispute->created ?? null),
                lastEventId: $this->requiredString($eventId, 'event id'),
                lastEventType: $this->requiredString($eventType, 'event type'),
                lastEventCreatedAt: $eventCreatedAt,
            ));

            $this->logger->info('Stripe dispute lifecycle state stored', [
                'dispute_id' => $disputeId,
                'status' => $status,
                'order_id' => $stripePayment?->getOrderId(),
                'stripe_account_id' => $stripeAccountId,
                'event_id' => $eventId,
            ]);
        });
    }

    private function findStripePayment(
        ?string $paymentIntentId,
        ?string $chargeId,
        ?string $stripeAccountId,
    ): ?StripePaymentDomainObject {
        $accountCriteria = [
            StripePaymentDomainObjectAbstract::CONNECTED_ACCOUNT_ID => $stripeAccountId,
        ];

        if ($paymentIntentId !== null) {
            $payment = $this->stripePaymentsRepository->findFirstWhere([
                StripePaymentDomainObjectAbstract::PAYMENT_INTENT_ID => $paymentIntentId,
                ...$accountCriteria,
            ]);

            if ($payment instanceof StripePaymentDomainObject) {
                return $payment;
            }
        }

        if ($chargeId !== null) {
            $payment = $this->stripePaymentsRepository->findFirstWhere([
                StripePaymentDomainObjectAbstract::CHARGE_ID => $chargeId,
                ...$accountCriteria,
            ]);

            if ($payment instanceof StripePaymentDomainObject) {
                return $payment;
            }
        }

        return null;
    }

    private function requiredString(mixed $value, string $field): string
    {
        $normalized = $this->optionalString($value);

        if ($normalized === null) {
            throw new UnexpectedValueException('Stripe '.$field.' is missing.');
        }

        return $normalized;
    }

    private function optionalString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }

    private function expandableId(mixed $value): ?string
    {
        if (is_string($value)) {
            return $this->optionalString($value);
        }

        if (is_object($value)) {
            return $this->optionalString($value->id ?? null);
        }

        return null;
    }

    private function expandableIds(mixed $value): array
    {
        $items = is_object($value) && isset($value->data) ? $value->data : $value;

        if (! is_array($items)) {
            return [];
        }

        $ids = [];

        foreach ($items as $item) {
            $id = $this->expandableId($item);

            if ($id !== null) {
                $ids[$id] = $id;
            }
        }

        return array_values($ids);
    }

    private function timestamp(mixed $value): ?DateTimeImmutable
    {
        if (! is_int($value) || $value < 0) {
            return null;
        }

        return (new DateTimeImmutable)->setTimestamp($value);
    }

    private function requiredTimestamp(mixed $value, string $field): DateTimeImmutable
    {
        $timestamp = $this->timestamp($value);

        if ($timestamp === null) {
            throw new UnexpectedValueException('Stripe '.$field.' is invalid.');
        }

        return $timestamp;
    }
}
