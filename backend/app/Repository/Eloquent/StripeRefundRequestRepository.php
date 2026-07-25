<?php

declare(strict_types=1);

namespace HiEvents\Repository\Eloquent;

use HiEvents\DomainObjects\Enums\StripeRefundRequestStatus;
use HiEvents\Exceptions\Stripe\StripeRefundRequestConflictException;
use HiEvents\Models\StripeRefundRequest;
use HiEvents\Repository\Interfaces\StripeRefundRequestRepositoryInterface;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\CreateStripeRefundRequestDTO;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\StripeRefundRequestClaimDTO;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\StripeRefundRequestRecordDTO;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class StripeRefundRequestRepository implements StripeRefundRequestRepositoryInterface
{
    public function claimOrLoad(CreateStripeRefundRequestDTO $request): StripeRefundRequestClaimDTO
    {
        $now = now();
        $inserted = StripeRefundRequest::query()->insertOrIgnore([
            'request_id' => $request->requestId,
            'order_id' => $request->orderId,
            'stripe_payment_id' => $request->stripePaymentId,
            'payment_intent_id' => $request->paymentIntentId,
            'stripe_account_id' => $request->stripeAccountId,
            'amount_minor' => $request->amountMinor,
            'currency' => strtoupper($request->currency),
            'notify_buyer' => $request->notifyBuyer,
            'cancel_order' => $request->cancelOrder,
            'refund_application_fee' => $request->refundApplicationFee,
            'status' => StripeRefundRequestStatus::CREATED->value,
            'attempts' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $model = $this->queryByRequestId($request->requestId)->lockForUpdate()->firstOrFail();
        $this->assertImmutablePayloadMatches($model, $request);

        return new StripeRefundRequestClaimDTO(
            request: $this->toDTO($model),
            created: $inserted === 1,
        );
    }

    public function findByRequestId(
        string $requestId,
        bool $forUpdate = false,
    ): ?StripeRefundRequestRecordDTO {
        $query = $this->queryByRequestId($requestId);
        if ($forUpdate) {
            $query->lockForUpdate();
        }

        $model = $query->first();

        return $model === null ? null : $this->toDTO($model);
    }

    public function recordAttempt(string $requestId): void
    {
        $this->queryByRequestId($requestId)->update([
            'attempts' => DB::raw('attempts + 1'),
            'last_attempted_at' => now(),
            'last_error_class' => null,
            'updated_at' => now(),
        ]);
    }

    public function recordProviderError(string $requestId, string $errorClass): void
    {
        $this->queryByRequestId($requestId)->update([
            'last_error_class' => substr($errorClass, 0, 255),
            'updated_at' => now(),
        ]);
    }

    public function recordProviderResult(
        string $requestId,
        string $providerRefundId,
        string $providerStatus,
        bool $terminal = false,
    ): StripeRefundRequestRecordDTO {
        $model = $this->queryByRequestId($requestId)->lockForUpdate()->firstOrFail();
        if ($model->provider_refund_id !== null && $model->provider_refund_id !== $providerRefundId) {
            throw new StripeRefundRequestConflictException(
                __('This refund request is already associated with a different provider refund.')
            );
        }

        $targetStatus = $terminal
            ? $this->requestStatusForProviderStatus($providerStatus)
            : StripeRefundRequestStatus::PROVIDER_ACCEPTED;
        $currentStatus = StripeRefundRequestStatus::from($model->status);
        if ($this->isTerminal($currentStatus) && $currentStatus !== $targetStatus) {
            if (! $this->isTerminal($targetStatus)) {
                $targetStatus = $currentStatus;
            } else {
                throw new StripeRefundRequestConflictException(
                    __('The payment provider returned conflicting terminal states for this refund request.')
                );
            }
        }

        $model->fill([
            'provider_refund_id' => $providerRefundId,
            'provider_status' => $providerStatus,
            'status' => $targetStatus->value,
            'provider_accepted_at' => $model->provider_accepted_at ?? now(),
            'last_error_class' => null,
        ]);
        $model->save();

        return $this->toDTO($model->fresh());
    }

    public function markCancelApplied(string $requestId): void
    {
        $this->queryByRequestId($requestId)
            ->whereNull('cancel_applied_at')
            ->update([
                'cancel_applied_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function claimNotification(string $requestId): bool
    {
        return $this->queryByRequestId($requestId)
            ->where('notify_buyer', true)
            ->whereNotNull('provider_refund_id')
            ->where('status', '!=', StripeRefundRequestStatus::FAILED->value)
            ->whereNull('notification_claimed_at')
            ->whereNull('notification_sent_at')
            ->update([
                'notification_claimed_at' => now(),
                'updated_at' => now(),
            ]) === 1;
    }

    public function markNotificationSent(string $requestId): void
    {
        $this->queryByRequestId($requestId)
            ->whereNotNull('notification_claimed_at')
            ->whereNull('notification_sent_at')
            ->update([
                'notification_sent_at' => now(),
                'updated_at' => now(),
            ]);
    }

    public function releaseNotificationClaim(string $requestId): void
    {
        $this->queryByRequestId($requestId)
            ->whereNull('notification_sent_at')
            ->update([
                'notification_claimed_at' => null,
                'updated_at' => now(),
            ]);
    }

    private function queryByRequestId(string $requestId): Builder
    {
        return StripeRefundRequest::query()->where('request_id', $requestId);
    }

    private function assertImmutablePayloadMatches(
        StripeRefundRequest $model,
        CreateStripeRefundRequestDTO $request,
    ): void {
        if ((int) $model->order_id !== $request->orderId
            || (int) $model->stripe_payment_id !== $request->stripePaymentId
            || $model->payment_intent_id !== $request->paymentIntentId
            || $model->stripe_account_id !== $request->stripeAccountId
            || (int) $model->amount_minor !== $request->amountMinor
            || strtoupper((string) $model->currency) !== strtoupper($request->currency)
            || (bool) $model->notify_buyer !== $request->notifyBuyer
            || (bool) $model->cancel_order !== $request->cancelOrder
            || $model->refund_application_fee !== $request->refundApplicationFee) {
            throw new StripeRefundRequestConflictException(
                __('This refund request identifier was already used with different refund details.')
            );
        }
    }

    private function requestStatusForProviderStatus(string $providerStatus): StripeRefundRequestStatus
    {
        return match (strtolower($providerStatus)) {
            'succeeded' => StripeRefundRequestStatus::SUCCEEDED,
            'failed', 'canceled' => StripeRefundRequestStatus::FAILED,
            default => StripeRefundRequestStatus::PROVIDER_ACCEPTED,
        };
    }

    private function isTerminal(StripeRefundRequestStatus $status): bool
    {
        return in_array($status, [
            StripeRefundRequestStatus::SUCCEEDED,
            StripeRefundRequestStatus::FAILED,
        ], true);
    }

    private function toDTO(StripeRefundRequest $model): StripeRefundRequestRecordDTO
    {
        return new StripeRefundRequestRecordDTO(
            id: (int) $model->id,
            requestId: $model->request_id,
            orderId: (int) $model->order_id,
            stripePaymentId: (int) $model->stripe_payment_id,
            paymentIntentId: $model->payment_intent_id,
            stripeAccountId: $model->stripe_account_id,
            amountMinor: (int) $model->amount_minor,
            currency: $model->currency,
            notifyBuyer: (bool) $model->notify_buyer,
            cancelOrder: (bool) $model->cancel_order,
            refundApplicationFee: $model->refund_application_fee,
            status: $model->status,
            attempts: (int) $model->attempts,
            providerRefundId: $model->provider_refund_id,
            providerStatus: $model->provider_status,
            cancelApplied: $model->cancel_applied_at !== null,
            notificationClaimed: $model->notification_claimed_at !== null,
            notificationSent: $model->notification_sent_at !== null,
        );
    }
}
