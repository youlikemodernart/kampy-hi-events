<?php

namespace Tests\Unit\Services\Domain\Payment\Stripe;

use HiEvents\DomainObjects\AccountConfigurationDomainObject;
use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\EventDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\DomainObjects\OrderDomainObject;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\Exceptions\Stripe\KampStripeMetadataConfigurationException;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\CreatePaymentIntentRequestDTO;
use HiEvents\Services\Domain\Payment\Stripe\KampStripeMetadataService;
use HiEvents\Values\MoneyValue;
use Illuminate\Config\Repository;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class KampStripeMetadataServiceTest extends TestCase
{
    private function config(array $overrides = []): Repository
    {
        return new Repository(array_replace_recursive([
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
        ], $overrides));
    }

    private function request(
        ?array $items = null,
        float $fixedFee = 6.00,
        float $percentageFee = 0.00,
        bool $passFeeToBuyer = true,
        string $currency = 'USD',
        string $stripeEnvironment = 'test',
        int $eventId = 3,
    ): CreatePaymentIntentRequestDTO {
        $accountConfiguration = (new AccountConfigurationDomainObject)
            ->setApplicationFees([
                'fixed' => $fixedFee,
                'percentage' => $percentageFee,
                'currency' => 'USD',
            ])
            ->setBypassApplicationFees(false);

        $account = (new AccountDomainObject)->setId(1);
        $account->setConfiguration($accountConfiguration);

        $eventSettings = (new EventSettingDomainObject)
            ->setPassPlatformFeeToBuyer($passFeeToBuyer);
        $event = (new EventDomainObject)->setId($eventId);
        $event->setEventSettings($eventSettings);

        $items ??= [
            $this->item(50.95, 2),
            $this->item(0.00, 1),
        ];
        $totalBeforeAdditions = array_sum(array_map(
            static fn (OrderItemDomainObject $item): float => $item->getTotalBeforeAdditions(),
            $items,
        ));
        $totalFee = array_sum(array_map(
            static fn (OrderItemDomainObject $item): float => $item->getTotalServiceFee(),
            $items,
        ));
        $totalGross = array_sum(array_map(
            static fn (OrderItemDomainObject $item): float => $item->getTotalGross(),
            $items,
        ));

        $order = (new OrderDomainObject)
            ->setId(27)
            ->setEventId($eventId)
            ->setShortId('order_test_001')
            ->setCurrency($currency)
            ->setTotalBeforeAdditions($totalBeforeAdditions)
            ->setTotalFee($totalFee)
            ->setTotalTax(0.00)
            ->setTotalGross($totalGross)
            ->setOrderItems(collect($items));
        $order->setEvent($event);

        return new CreatePaymentIntentRequestDTO(
            amount: MoneyValue::fromFloat($totalGross, $currency),
            currencyCode: $currency,
            account: $account,
            order: $order,
            stripeAccountId: 'acct_connectedfixture',
            description: 'Existing Hi.Events description',
            stripeEnvironment: $stripeEnvironment,
        );
    }

    private function item(float $price, int $quantity, string $type = ProductType::TICKET->name): OrderItemDomainObject
    {
        $totalBeforeAdditions = $price * $quantity;
        $platformFee = $price > 0 ? 6.00 * $quantity : 0.00;
        $rollup = $platformFee > 0 ? [
            'fees' => [[
                'id' => 0,
                'name' => 'Platform Fee',
                'rate' => $platformFee,
                'type' => 'FIXED',
                'value' => $platformFee,
            ]],
        ] : [];

        return (new OrderItemDomainObject)
            ->setPrice($price)
            ->setQuantity($quantity)
            ->setProductType($type)
            ->setTotalBeforeAdditions($totalBeforeAdditions)
            ->setTotalServiceFee($platformFee)
            ->setTotalTax(0.00)
            ->setTotalGross($totalBeforeAdditions + $platformFee)
            ->setTaxesAndFeesRollup($rollup);
    }

    public function test_builds_strict_v2_metadata_and_deterministic_description_for_paid_tickets(): void
    {
        $result = (new KampStripeMetadataService($this->config()))->build($this->request());

        $this->assertNotNull($result);
        $this->assertSame(2, $result->ticketQuantity);
        $this->assertSame([
            'kamp_university_id' => 'gcu',
            'kamp_cycle_id' => '2026-fall',
            'kamp_event_id' => 'gcu-kamp-fall-2026',
            'kamp_source' => 'hi_events',
            'kamp_schema_version' => '2026-07-24.2',
            'kamp_source_namespace' => 'kampy_ticketing',
            'kamp_source_record_id' => 'hi_order_record_27',
            'kamp_source_order_id' => 'hi_order_order_test_001',
            'kamp_ticket_quantity' => '2',
            'kamp_pricing_policy' => 'gcu-intended-net-v1',
            'kamp_fee_policy' => 'wawco-six-per-ticket-v1',
            'kamp_environment' => 'test',
            'kamp_adapter_version' => '2026-07-25.1',
            'kamp_reconciliation_ref' => 'hi_recon_order_27',
        ], $result->metadata);
        $this->assertSame(
            'Kamp | gcu | 2026-fall | gcu-kamp-fall-2026 | order:hi_order_order_test_001',
            $result->description,
        );
        $this->assertStringNotContainsString('email', implode('|', $result->metadata));
        $this->assertStringNotContainsString('address', implode('|', $result->metadata));
    }

    public function test_disabled_feature_preserves_generic_hi_events_behavior(): void
    {
        $config = $this->config([
            'services' => ['kamp_stripe_metadata' => ['enabled' => false]],
        ]);

        $this->assertNull((new KampStripeMetadataService($config))->build($this->request()));
    }

    public function test_uses_environment_bound_to_the_selected_stripe_keypair(): void
    {
        $service = new KampStripeMetadataService($this->config());
        $environment = $service->resolveStripeEnvironment(
            'pk_live_invented_fixture',
            'sk_live_invented_fixture',
        );
        $result = $service->build($this->request(stripeEnvironment: $environment));

        $this->assertSame('live', $result->metadata['kamp_environment']);
    }

    public function test_rejects_mixed_selected_stripe_key_modes_without_revealing_keys(): void
    {
        try {
            (new KampStripeMetadataService($this->config()))->resolveStripeEnvironment(
                'pk_test_invented_fixture',
                'sk_live_invented_fixture',
            );
            $this->fail('Expected mixed Stripe key modes to fail closed.');
        } catch (KampStripeMetadataConfigurationException $exception) {
            $this->assertSame('stripe_key_mode_mismatch', $exception->getReason());
        }
    }

    #[DataProvider('invalidContractProvider')]
    public function test_fails_closed_on_fee_and_checkout_contract_drift(
        float $fixedFee,
        float $percentageFee,
        bool $passFeeToBuyer,
        string $expectedReason,
    ): void {
        try {
            (new KampStripeMetadataService($this->config()))->build(
                $this->request(
                    fixedFee: $fixedFee,
                    percentageFee: $percentageFee,
                    passFeeToBuyer: $passFeeToBuyer,
                ),
            );
            $this->fail('Expected the Kamp metadata contract to fail closed.');
        } catch (KampStripeMetadataConfigurationException $exception) {
            $this->assertSame($expectedReason, $exception->getReason());
        }
    }

    public static function invalidContractProvider(): array
    {
        return [
            'wrong fixed fee' => [5.99, 0.00, true, 'fixed_application_fee_mismatch'],
            'nonzero percentage fee' => [6.00, 1.50, true, 'percentage_application_fee_mismatch'],
            'buyer pass through disabled' => [6.00, 0.00, false, 'buyer_fee_pass_through_required'],
        ];
    }

    public function test_rejects_paid_non_ticket_items(): void
    {
        $this->expectException(KampStripeMetadataConfigurationException::class);

        try {
            (new KampStripeMetadataService($this->config()))->build(
                $this->request(items: [$this->item(50.95, 1, ProductType::GENERAL->name)]),
            );
        } catch (KampStripeMetadataConfigurationException $exception) {
            $this->assertSame('paid_non_ticket_item_forbidden', $exception->getReason());
            throw $exception;
        }
    }

    public function test_rejects_unknown_or_private_event_configuration_fields(): void
    {
        $config = $this->config([
            'services' => [
                'kamp_stripe_metadata' => [
                    'event_map' => [
                        '3' => [
                            'university_id' => 'gcu',
                            'cycle_id' => '2026-fall',
                            'event_id' => 'gcu-kamp-fall-2026',
                            'pricing_policy' => 'gcu-intended-net-v1',
                            'buyer_email' => 'private@example.com',
                        ],
                    ],
                ],
            ],
        ]);

        try {
            (new KampStripeMetadataService($config))->build($this->request());
            $this->fail('Expected unknown event configuration to fail closed.');
        } catch (KampStripeMetadataConfigurationException $exception) {
            $this->assertSame('unknown_event_configuration_field', $exception->getReason());
        }
    }

    public function test_requires_campaign_when_allocation_is_configured(): void
    {
        $config = $this->config([
            'services' => [
                'kamp_stripe_metadata' => [
                    'event_map' => [
                        '3' => [
                            'university_id' => 'gcu',
                            'cycle_id' => '2026-fall',
                            'event_id' => 'gcu-kamp-fall-2026',
                            'pricing_policy' => 'gcu-intended-net-v1',
                            'allocation_id' => 'allocation_001',
                        ],
                    ],
                ],
            ],
        ]);

        try {
            (new KampStripeMetadataService($config))->build($this->request());
            $this->fail('Expected allocation without campaign to fail closed.');
        } catch (KampStripeMetadataConfigurationException $exception) {
            $this->assertSame('campaign_id_required', $exception->getReason());
        }
    }

    public function test_installation_lockdown_rejects_an_unmapped_event_when_enabled(): void
    {
        try {
            (new KampStripeMetadataService($this->config()))->build($this->request(eventId: 4));
            $this->fail('Expected an unmapped event to fail closed while Kamp mode is enabled.');
        } catch (KampStripeMetadataConfigurationException $exception) {
            $this->assertSame('event_not_configured', $exception->getReason());
        }
    }

    public function test_accepts_a_valid_json_string_event_map(): void
    {
        $eventMap = json_encode([
            '3' => [
                'university_id' => 'gcu',
                'cycle_id' => '2026-fall',
                'event_id' => 'gcu-kamp-fall-2026',
                'pricing_policy' => 'gcu-intended-net-v1',
            ],
        ], JSON_THROW_ON_ERROR);
        $config = $this->config([
            'services' => ['kamp_stripe_metadata' => ['event_map' => $eventMap]],
        ]);

        $this->assertNotNull((new KampStripeMetadataService($config))->build($this->request()));
    }

    public function test_rejects_missing_buyer_fee_in_the_persisted_order(): void
    {
        $item = $this->item(50.95, 2);
        $item
            ->setTotalServiceFee(0.00)
            ->setTotalGross(101.90)
            ->setTaxesAndFeesRollup([]);

        try {
            (new KampStripeMetadataService($this->config()))->build($this->request(items: [$item]));
            $this->fail('Expected missing buyer pass-through amount to fail closed.');
        } catch (KampStripeMetadataConfigurationException $exception) {
            $this->assertSame('unexpected_ticket_addition', $exception->getReason());
        }
    }

    public function test_rejects_secret_shaped_content_in_an_allowed_optional_field(): void
    {
        $config = $this->config([
            'services' => [
                'kamp_stripe_metadata' => [
                    'event_map' => [
                        '3' => [
                            'university_id' => 'gcu',
                            'cycle_id' => '2026-fall',
                            'event_id' => 'gcu-kamp-fall-2026',
                            'pricing_policy' => 'gcu-intended-net-v1',
                            'campaign_id' => 'AKIA'.'ABCDEFGHIJKLMNOP',
                        ],
                    ],
                ],
            ],
        ]);

        try {
            (new KampStripeMetadataService($config))->build($this->request());
            $this->fail('Expected secret-shaped campaign ID to fail closed.');
        } catch (KampStripeMetadataConfigurationException $exception) {
            $this->assertSame('invalid_campaign_id', $exception->getReason());
        }
    }
}
