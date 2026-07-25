<?php

namespace Tests\Unit\Services\Domain\Payment\Stripe;

use HiEvents\DomainObjects\AccountConfigurationDomainObject;
use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\DomainObjects\StripeCustomerDomainObject;
use HiEvents\Exceptions\Stripe\KampStripeMetadataConfigurationException;
use HiEvents\Repository\Interfaces\StripeCustomerRepositoryInterface;
use HiEvents\Services\Domain\Order\DTO\ApplicationFeeValuesDTO;
use HiEvents\Services\Domain\Order\OrderApplicationFeeCalculationService;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\CreatePaymentIntentRequestDTO;
use HiEvents\Services\Domain\Payment\Stripe\KampStripeMetadataService;
use HiEvents\Services\Domain\Payment\Stripe\StripePaymentIntentCreationService;
use HiEvents\Values\MoneyValue;
use Illuminate\Config\Repository;
use Illuminate\Database\DatabaseManager;
use Mockery;
use Psr\Log\LoggerInterface;
use Stripe\Customer;
use Stripe\PaymentIntent;
use Stripe\StripeClient;
use Tests\TestCase;

class PaymentIntentServiceDouble
{
    public function create(array $params, array $options): PaymentIntent
    {
        return new PaymentIntent;
    }

    public function retrieve(string $id, array $params, array $options): PaymentIntent
    {
        return new PaymentIntent;
    }
}

class CustomerServiceDouble
{
    public function create(array $params, array $opts): Customer
    {
        return new Customer;
    }
}

class StripePaymentIntentCreationServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    private function config(): Repository
    {
        return new Repository([
            'app' => [
                'saas_mode_enabled' => true,
            ],
            'services' => [
                'stripe' => [
                    'public_key' => 'pk_test_invented_fixture',
                ],
                'kamp_stripe_metadata' => [
                    'enabled' => true,
                    'source_namespace' => 'kampy_ticketing',
                    'event_map' => [
                        '3' => [
                            'university_id' => 'gcu',
                            'cycle_id' => '2026-fall',
                            'event_id' => 'gcu-kamp-fall-2026',
                            'pricing_policy' => 'gcu-intended-net-v1',
                        ],
                    ],
                ],
            ],
        ]);
    }

    private function request(): CreatePaymentIntentRequestDTO
    {
        $accountConfiguration = (new AccountConfigurationDomainObject)
            ->setApplicationFees([
                'fixed' => 6.00,
                'percentage' => 0.00,
                'currency' => 'USD',
            ])
            ->setBypassApplicationFees(false);

        $account = (new AccountDomainObject)->setId(1);
        $account->setConfiguration($accountConfiguration);

        $eventSettings = (new EventSettingDomainObject)->setPassPlatformFeeToBuyer(true);
        $event = (new EventDomainObject)->setId(3);
        $event->setEventSettings($eventSettings);

        $order = (new OrderDomainObject)
            ->setId(27)
            ->setEventId(3)
            ->setShortId('order_test_001')
            ->setPublicId('ord_public_invented_001')
            ->setCurrency('USD')
            ->setTotalBeforeAdditions(101.90)
            ->setTotalFee(12.00)
            ->setTotalTax(0.00)
            ->setTotalGross(113.90)
            ->setFirstName('Invented')
            ->setLastName('Buyer')
            ->setEmail('invented@example.invalid')
            ->setOrderItems(collect([
                (new OrderItemDomainObject)
                    ->setPrice(50.95)
                    ->setQuantity(2)
                    ->setProductType(ProductType::TICKET->name)
                    ->setTotalBeforeAdditions(101.90)
                    ->setTotalServiceFee(12.00)
                    ->setTotalTax(0.00)
                    ->setTotalGross(113.90)
                    ->setTaxesAndFeesRollup([
                        'fees' => [[
                            'id' => 0,
                            'name' => 'Platform Fee',
                            'rate' => 12.00,
                            'type' => 'FIXED',
                            'value' => 12.00,
                        ]],
                    ]),
            ]));
        $order->setEvent($event);

        return new CreatePaymentIntentRequestDTO(
            amount: MoneyValue::fromFloat(113.90, 'USD'),
            currencyCode: 'USD',
            account: $account,
            order: $order,
            stripeAccountId: 'acct_connectedfixture',
            description: 'Existing description',
            stripeEnvironment: 'test',
        );
    }

    private function existingCustomer(): StripeCustomerDomainObject
    {
        return (new StripeCustomerDomainObject)
            ->setId(1)
            ->setName('Invented Buyer')
            ->setEmail('invented@example.invalid')
            ->setStripeCustomerId('cus_invented_fixture')
            ->setStripeAccountId('acct_connectedfixture');
    }

    private function stripeClient(object $paymentIntentService, ?object $customerService = null): StripeClient
    {
        $stripeClient = new class('sk_test_invented_fixture') extends StripeClient
        {
            public mixed $paymentIntents;

            public mixed $customers;
        };
        $stripeClient->paymentIntents = $paymentIntentService;
        if ($customerService !== null) {
            $stripeClient->customers = $customerService;
        }

        return $stripeClient;
    }

    private function service(
        Repository $config,
        StripeCustomerRepositoryInterface $customerRepository,
        DatabaseManager $databaseManager,
        OrderApplicationFeeCalculationService $feeService,
    ): StripePaymentIntentCreationService {
        return new StripePaymentIntentCreationService(
            $this->createMock(LoggerInterface::class),
            $config,
            $customerRepository,
            $databaseManager,
            $feeService,
            new KampStripeMetadataService($config),
        );
    }

    public function test_creates_direct_charge_with_fixed_fee_and_strict_kamp_metadata(): void
    {
        $request = $this->request();
        $config = $this->config();

        $paymentIntents = $this->createMock(PaymentIntentServiceDouble::class);
        $paymentIntents->expects($this->once())
            ->method('create')
            ->with(
                $this->callback(static function (array $params): bool {
                    $metadata = $params['metadata'];

                    $expectedKeys = [
                        'kamp_adapter_version',
                        'kamp_cycle_id',
                        'kamp_environment',
                        'kamp_event_id',
                        'kamp_fee_policy',
                        'kamp_pricing_policy',
                        'kamp_reconciliation_ref',
                        'kamp_schema_version',
                        'kamp_source',
                        'kamp_source_namespace',
                        'kamp_source_order_id',
                        'kamp_source_record_id',
                        'kamp_ticket_quantity',
                        'kamp_university_id',
                    ];
                    $actualKeys = array_keys($metadata);
                    sort($actualKeys);

                    return $params['amount'] === 11390
                        && $params['currency'] === 'USD'
                        && $params['customer'] === 'cus_invented_fixture'
                        && $params['application_fee_amount'] === 1200
                        && $actualKeys === $expectedKeys
                        && $metadata['kamp_schema_version'] === '2026-07-24.2'
                        && $metadata['kamp_source'] === 'hi_events'
                        && $metadata['kamp_source_namespace'] === 'kampy_ticketing'
                        && $metadata['kamp_ticket_quantity'] === '2'
                        && $metadata['kamp_fee_policy'] === 'wawco-six-per-ticket-v1'
                        && $metadata['kamp_environment'] === 'test'
                        && ! in_array('invented@example.invalid', $metadata, true)
                        && $params['description'] === 'Kamp | gcu | 2026-fall | gcu-kamp-fall-2026 | order:hi_order_order_test_001';
                }),
                [
                    'stripe_account' => 'acct_connectedfixture',
                    'idempotency_key' => 'hie:payment-intent:v1:'.hash('sha256', 'ord_public_invented_001'),
                ],
            )
            ->willReturn(PaymentIntent::constructFrom([
                'id' => 'pi_invented_fixture',
                'client_secret' => 'pi_invented_fixture_secret',
            ]));

        $customerRepository = $this->createMock(StripeCustomerRepositoryInterface::class);
        $customerRepository->expects($this->once())
            ->method('findFirstWhere')
            ->with([
                'email' => 'invented@example.invalid',
                'stripe_account_id' => 'acct_connectedfixture',
            ])
            ->willReturn($this->existingCustomer());

        $databaseManager = Mockery::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('beginTransaction')->once();
        $databaseManager->shouldReceive('commit')->once();
        $databaseManager->shouldReceive('rollBack')->never();

        $applicationFee = new ApplicationFeeValuesDTO(
            grossApplicationFee: MoneyValue::fromFloat(12.00, 'USD'),
            netApplicationFee: MoneyValue::fromFloat(12.00, 'USD'),
        );
        $feeService = $this->createMock(OrderApplicationFeeCalculationService::class);
        $feeService->expects($this->once())
            ->method('calculateApplicationFee')
            ->willReturn($applicationFee);

        $response = $this->service(
            $config,
            $customerRepository,
            $databaseManager,
            $feeService,
        )->createPaymentIntentWithClient($this->stripeClient($paymentIntents), $request);

        $this->assertSame('pi_invented_fixture', $response->paymentIntentId);
        $this->assertSame('acct_connectedfixture', $response->accountId);
        $this->assertSame($applicationFee, $response->applicationFeeData);
    }

    public function test_customer_creation_uses_a_stable_order_key_before_payment_intent_creation(): void
    {
        $request = $this->request();
        $config = $this->config();

        $customers = $this->createMock(CustomerServiceDouble::class);
        $customers->expects($this->once())
            ->method('create')
            ->with(
                $this->callback(static fn (array $params): bool => $params['email'] === 'invented@example.invalid'),
                [
                    'stripe_account' => 'acct_connectedfixture',
                    'idempotency_key' => 'hie:customer:v1:'.hash('sha256', 'ord_public_invented_001'),
                ],
            )
            ->willReturn(Customer::constructFrom([
                'id' => 'cus_invented_fixture',
                'name' => 'Invented Buyer',
                'email' => 'invented@example.invalid',
            ]));

        $paymentIntents = $this->createMock(PaymentIntentServiceDouble::class);
        $paymentIntents->expects($this->once())
            ->method('create')
            ->willReturn(PaymentIntent::constructFrom([
                'id' => 'pi_invented_fixture',
                'client_secret' => 'pi_invented_fixture_secret',
            ]));

        $customerRepository = $this->createMock(StripeCustomerRepositoryInterface::class);
        $customerRepository->expects($this->once())
            ->method('findFirstWhere')
            ->willReturn(null);
        $customerRepository->expects($this->once())
            ->method('create')
            ->willReturn($this->existingCustomer());

        $databaseManager = Mockery::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('beginTransaction')->once();
        $databaseManager->shouldReceive('commit')->once();
        $databaseManager->shouldReceive('rollBack')->never();

        $feeService = $this->createMock(OrderApplicationFeeCalculationService::class);
        $feeService->expects($this->once())
            ->method('calculateApplicationFee')
            ->willReturn(new ApplicationFeeValuesDTO(
                grossApplicationFee: MoneyValue::fromFloat(12.00, 'USD'),
                netApplicationFee: MoneyValue::fromFloat(12.00, 'USD'),
            ));

        $this->service(
            $config,
            $customerRepository,
            $databaseManager,
            $feeService,
        )->createPaymentIntentWithClient(
            $this->stripeClient($paymentIntents, $customers),
            $request,
        );
    }

    public function test_rejects_application_fee_amount_that_does_not_match_ticket_quantity(): void
    {
        $request = $this->request();
        $config = $this->config();

        $paymentIntents = $this->createMock(PaymentIntentServiceDouble::class);
        $paymentIntents->expects($this->never())->method('create');

        $customerRepository = $this->createMock(StripeCustomerRepositoryInterface::class);
        $customerRepository->expects($this->never())->method('findFirstWhere');

        $databaseManager = Mockery::mock(DatabaseManager::class);
        $databaseManager->shouldReceive('beginTransaction')->once();
        $databaseManager->shouldReceive('commit')->never();
        $databaseManager->shouldReceive('rollBack')->once();

        $feeService = $this->createMock(OrderApplicationFeeCalculationService::class);
        $feeService->method('calculateApplicationFee')->willReturn(new ApplicationFeeValuesDTO(
            grossApplicationFee: MoneyValue::fromFloat(6.00, 'USD'),
            netApplicationFee: MoneyValue::fromFloat(6.00, 'USD'),
        ));

        try {
            $this->service(
                $config,
                $customerRepository,
                $databaseManager,
                $feeService,
            )->createPaymentIntentWithClient($this->stripeClient($paymentIntents), $request);
            $this->fail('Expected application fee mismatch to stop before Stripe object creation.');
        } catch (KampStripeMetadataConfigurationException $exception) {
            $this->assertSame('application_fee_amount_mismatch', $exception->getReason());
        }
    }

    public function test_revalidates_an_existing_payment_intent_before_reuse(): void
    {
        $request = $this->request();
        $config = $this->config();
        $kampMetadata = (new KampStripeMetadataService($config))->build($request);
        $paymentIntent = PaymentIntent::constructFrom([
            'id' => 'pi_existing_fixture',
            'client_secret' => 'pi_existing_fixture_secret',
            'amount' => 11390,
            'currency' => 'usd',
            'application_fee_amount' => 1200,
            'metadata' => $kampMetadata->metadata,
            'description' => $kampMetadata->description,
        ]);

        $paymentIntents = $this->createMock(PaymentIntentServiceDouble::class);
        $paymentIntents->expects($this->once())
            ->method('retrieve')
            ->with('pi_existing_fixture', [], ['stripe_account' => 'acct_connectedfixture'])
            ->willReturn($paymentIntent);

        $feeService = $this->createMock(OrderApplicationFeeCalculationService::class);
        $feeService->expects($this->once())
            ->method('calculateApplicationFee')
            ->willReturn(new ApplicationFeeValuesDTO(
                grossApplicationFee: MoneyValue::fromFloat(12.00, 'USD'),
                netApplicationFee: MoneyValue::fromFloat(12.00, 'USD'),
            ));

        $clientSecret = $this->service(
            $config,
            $this->createMock(StripeCustomerRepositoryInterface::class),
            Mockery::mock(DatabaseManager::class),
            $feeService,
        )->retrieveValidatedPaymentIntentClientSecretWithClient(
            $this->stripeClient($paymentIntents),
            'pi_existing_fixture',
            $request,
        );

        $this->assertSame('pi_existing_fixture_secret', $clientSecret);
    }

    public function test_rejects_an_existing_payment_intent_with_legacy_metadata(): void
    {
        $request = $this->request();
        $config = $this->config();
        $paymentIntent = PaymentIntent::constructFrom([
            'id' => 'pi_existing_fixture',
            'client_secret' => 'pi_existing_fixture_secret',
            'amount' => 11390,
            'currency' => 'usd',
            'application_fee_amount' => 1200,
            'metadata' => ['order_id' => '27'],
            'description' => 'Old generic description',
        ]);

        $paymentIntents = $this->createMock(PaymentIntentServiceDouble::class);
        $paymentIntents->expects($this->once())
            ->method('retrieve')
            ->willReturn($paymentIntent);

        $feeService = $this->createMock(OrderApplicationFeeCalculationService::class);
        $feeService->expects($this->once())
            ->method('calculateApplicationFee')
            ->willReturn(new ApplicationFeeValuesDTO(
                grossApplicationFee: MoneyValue::fromFloat(12.00, 'USD'),
                netApplicationFee: MoneyValue::fromFloat(12.00, 'USD'),
            ));

        try {
            $this->service(
                $config,
                $this->createMock(StripeCustomerRepositoryInterface::class),
                Mockery::mock(DatabaseManager::class),
                $feeService,
            )->retrieveValidatedPaymentIntentClientSecretWithClient(
                $this->stripeClient($paymentIntents),
                'pi_existing_fixture',
                $request,
            );
            $this->fail('Expected legacy PaymentIntent metadata to fail closed.');
        } catch (KampStripeMetadataConfigurationException $exception) {
            $this->assertSame('existing_payment_intent_contract_mismatch', $exception->getReason());
        }
    }
}
