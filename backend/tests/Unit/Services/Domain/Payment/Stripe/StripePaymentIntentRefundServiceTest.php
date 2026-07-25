<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Domain\Payment\Stripe;

use HiEvents\DomainObjects\StripePaymentDomainObject;
use HiEvents\Services\Domain\Payment\Stripe\StripePaymentIntentRefundService;
use HiEvents\Values\MoneyValue;
use Illuminate\Config\Repository;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Stripe\Refund;
use Stripe\StripeClient;

class RefundServiceDouble
{
    public function create(array $params, array $opts): Refund
    {
        return new Refund;
    }
}

class StripePaymentIntentRefundServiceTest extends TestCase
{
    public function test_return_policy_explicitly_refunds_application_fee(): void
    {
        $requestId = '0f51dbea-f04b-4a39-8d84-e861aac14e55';
        $refunds = $this->createMock(RefundServiceDouble::class);
        $refunds->expects(self::once())
            ->method('create')
            ->with(
                [
                    'payment_intent' => 'pi_test_refund',
                    'amount' => 5695,
                    'metadata' => ['hie_refund_request_id' => $requestId],
                    'refund_application_fee' => true,
                ],
                [
                    'stripe_account' => 'acct_test_connected',
                    'idempotency_key' => 'hie:refund:v1:'.$requestId,
                ],
            )
            ->willReturn(new Refund);

        $service = $this->service('return');
        $service->refundPayment(
            MoneyValue::fromFloat(56.95, 'USD'),
            $this->payment(),
            $this->stripeClient($refunds),
            $requestId,
            $service->resolveRefundApplicationFee(),
        );
    }

    public function test_retain_policy_explicitly_keeps_application_fee(): void
    {
        $requestId = 'bf656026-cbc5-471d-ac45-3683fd24cb62';
        $refunds = $this->createMock(RefundServiceDouble::class);
        $refunds->expects(self::once())
            ->method('create')
            ->with(
                [
                    'payment_intent' => 'pi_test_refund',
                    'amount' => 5695,
                    'metadata' => ['hie_refund_request_id' => $requestId],
                    'refund_application_fee' => false,
                ],
                [
                    'stripe_account' => 'acct_test_connected',
                    'idempotency_key' => 'hie:refund:v1:'.$requestId,
                ],
            )
            ->willReturn(new Refund);

        $service = $this->service('retain');
        $service->refundPayment(
            MoneyValue::fromFloat(56.95, 'USD'),
            $this->payment(),
            $this->stripeClient($refunds),
            $requestId,
            $service->resolveRefundApplicationFee(),
        );
    }

    public function test_connected_refund_fails_closed_when_policy_is_missing(): void
    {
        $refunds = $this->createMock(RefundServiceDouble::class);
        $refunds->expects(self::never())->method('create');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Stripe application fee refund policy must be retain or return');

        $this->service(null)->resolveRefundApplicationFee();
    }

    public function test_non_saas_refund_uses_idempotency_without_connect_only_parameter(): void
    {
        $requestId = 'c668c413-1c9b-4586-812b-217871b76bf7';
        $refunds = $this->createMock(RefundServiceDouble::class);
        $refunds->expects(self::once())
            ->method('create')
            ->with(
                [
                    'payment_intent' => 'pi_test_refund',
                    'amount' => 5695,
                    'metadata' => ['hie_refund_request_id' => $requestId],
                ],
                ['idempotency_key' => 'hie:refund:v1:'.$requestId],
            )
            ->willReturn(new Refund);

        $config = new Repository([
            'app' => ['saas_mode_enabled' => false],
            'services' => ['stripe' => ['connect_refund_application_fee_policy' => null]],
        ]);
        $service = new StripePaymentIntentRefundService($config);

        $service->refundPayment(
            MoneyValue::fromFloat(56.95, 'USD'),
            $this->payment(),
            $this->stripeClient($refunds),
            $requestId,
            $service->resolveRefundApplicationFee(),
        );
    }

    private function service(?string $policy): StripePaymentIntentRefundService
    {
        return new StripePaymentIntentRefundService(new Repository([
            'app' => ['saas_mode_enabled' => true],
            'services' => [
                'stripe' => [
                    'connect_refund_application_fee_policy' => $policy,
                ],
            ],
        ]));
    }

    private function payment(): StripePaymentDomainObject
    {
        return (new StripePaymentDomainObject)
            ->setPaymentIntentId('pi_test_refund')
            ->setConnectedAccountId('acct_test_connected');
    }

    private function stripeClient(object $refundService): StripeClient
    {
        $stripeClient = new class('sk_test_invented_fixture') extends StripeClient
        {
            public mixed $refunds;
        };
        $stripeClient->refunds = $refundService;

        return $stripeClient;
    }
}
