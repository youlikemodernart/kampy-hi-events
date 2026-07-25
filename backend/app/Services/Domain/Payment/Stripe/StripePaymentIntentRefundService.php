<?php

namespace HiEvents\Services\Domain\Payment\Stripe;

use Brick\Math\Exception\MathException;
use HiEvents\DomainObjects\Enums\StripeConnectRefundApplicationFeePolicy;
use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Values\MoneyValue;
use Illuminate\Config\Repository;
use RuntimeException;
use Stripe\Exception\ApiErrorException;
use Stripe\Refund;
use Stripe\StripeClient;

class StripePaymentIntentRefundService
{
    public function __construct(
        private readonly Repository $config,
    ) {}

    /**
     * @throws ApiErrorException
     * @throws MathException
     *
     * @todo - catch and handle stripe errors
     */
    public function refundPayment(
        MoneyValue $amount,
        StripePaymentDomainObject $payment,
        StripeClient $stripeClient,
        string $refundRequestId,
        ?bool $refundApplicationFee,
    ): Refund {
        $params = [
            'payment_intent' => $payment->getPaymentIntentId(),
            'amount' => $amount->toMinorUnit(),
            'metadata' => [
                'hie_refund_request_id' => $refundRequestId,
            ],
        ];

        if ($this->config->get('app.saas_mode_enabled')) {
            if ($refundApplicationFee === null) {
                throw new RuntimeException(
                    'Cannot refund connected payment without a durable application fee refund decision'
                );
            }

            $params['refund_application_fee'] = $refundApplicationFee;
        }

        return $stripeClient->refunds->create(
            params: $params,
            opts: [
                ...$this->getStripeAccountData($payment),
                'idempotency_key' => 'hie:refund:v1:'.$refundRequestId,
            ],
        );
    }

    public function resolveRefundApplicationFee(): ?bool
    {
        if (! $this->config->get('app.saas_mode_enabled')) {
            return null;
        }

        return $this->shouldRefundApplicationFee();
    }

    private function shouldRefundApplicationFee(): bool
    {
        $configuredPolicy = $this->config->get('services.stripe.connect_refund_application_fee_policy');
        $policy = is_string($configuredPolicy)
            ? StripeConnectRefundApplicationFeePolicy::tryFrom(strtolower(trim($configuredPolicy)))
            : null;

        if ($policy === null) {
            throw new RuntimeException(
                'Cannot refund connected payment: Stripe application fee refund policy must be retain or return'
            );
        }

        return $policy === StripeConnectRefundApplicationFeePolicy::RETURN;
    }

    private function getStripeAccountData(StripePaymentDomainObject $payment): array
    {
        if ($this->config->get('app.saas_mode_enabled')) {
            if ($payment->getConnectedAccountId() === null) {
                throw new RuntimeException(
                    __('Cannot Refund: Stripe connect account not found and saas_mode_enabled is enabled')
                );
            }

            return [
                'stripe_account' => $payment->getConnectedAccountId(),
            ];
        }

        return [];
    }
}
