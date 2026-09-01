<?php

namespace HiEvents\Services\Domain\Payment\Stripe;

use HiEvents\DomainObjects\Enums\ProductType;
use HiEvents\DomainObjects\Enums\TaxCalculationType;
use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\Exceptions\Stripe\KampStripeMetadataConfigurationException;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\CreatePaymentIntentRequestDTO;
use HiEvents\Services\Domain\Payment\Stripe\DTOs\KampStripeMetadataDTO;
use HiEvents\Values\MoneyValue;
use Illuminate\Config\Repository;
use JsonException;

class KampStripeMetadataService
{
    public const SCHEMA_VERSION = '2026-08-20.1';

    public const ADAPTER_VERSION = '2026-08-20.1';

    public const SOURCE = 'hi_events';

    public const FEE_POLICY = 'wawco-three-kamplove-three-v1';

    public const FIXED_APPLICATION_FEE = 3.00;

    public const FIXED_BUYER_SERVICE_FEE = 6.00;

    public const APPLICATION_FEE_CURRENCY = 'USD';

    public const PERCENTAGE_APPLICATION_FEE = 0.00;

    public const MAX_DESCRIPTION_LENGTH = 200;

    private const EVENT_FIELDS = [
        'university_id',
        'cycle_id',
        'event_id',
        'pricing_policy',
        'service_fee_id',
        'source_namespace',
        'campaign_id',
        'allocation_id',
    ];

    private const SAFE_ID_PATTERN = '/\A[a-z0-9][a-z0-9_-]{1,79}\z/iD';

    private const SAFE_SOURCE_PATTERN = '/\A[a-z0-9][a-z0-9_-]{1,39}\z/iD';

    private const SAFE_POLICY_PATTERN = '/\A[a-z0-9][a-z0-9_-]{0,70}-v[1-9][0-9]{0,2}\z/D';

    private const SAFE_VERSION_PATTERN = '/\A\d{4}-\d{2}-\d{2}\.\d{1,3}\z/D';

    private const ASCII_PRINTABLE_PATTERN = '/\A[\x20-\x7E]+\z/D';

    private const PRIVATE_SECRET_PATTERN = '/[A-Z0-9._%+-]+@[A-Z0-9.-]+\.[A-Z]{2,}|bearer\s+|basic\s+|sk_|pk_|rk_|whsec_|xox[baprs]-|ghp_|AIza|(?:AKIA|ASIA)[A-Z0-9]{16}|eyJ[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+/i';

    private const PHONE_LIKE_PATTERN = '/\A\+?\d[\d\s().-]{7,}\z/D';

    private const PRIVATE_LABEL_PATTERN = '/(^|[_-])(email|phone|address|donor|participant|attendee|student|medical|health|emergency|card|last4|name)([_-]|$)/i';

    public function __construct(private readonly Repository $config) {}

    public function isEnabled(): bool
    {
        return (bool) $this->config->get('services.kamp_stripe_metadata.enabled', false);
    }

    public function resolveStripeEnvironment(?string $publicKey, ?string $secretKey): string
    {
        $publicMode = match (true) {
            is_string($publicKey) && str_starts_with($publicKey, 'pk_test_') => 'test',
            is_string($publicKey) && str_starts_with($publicKey, 'pk_live_') => 'live',
            default => null,
        };
        $secretMode = match (true) {
            is_string($secretKey) && (str_starts_with($secretKey, 'sk_test_') || str_starts_with($secretKey, 'rk_test_')) => 'test',
            is_string($secretKey) && (str_starts_with($secretKey, 'sk_live_') || str_starts_with($secretKey, 'rk_live_')) => 'live',
            default => null,
        };

        if ($publicMode === null || $secretMode === null || $publicMode !== $secretMode) {
            $this->fail('stripe_key_mode_mismatch');
        }

        return $publicMode;
    }

    public function build(CreatePaymentIntentRequestDTO $request): ?KampStripeMetadataDTO
    {
        if (! $this->isEnabled()) {
            return null;
        }

        $this->assertPaymentContract($request);

        $eventConfiguration = $this->eventConfiguration($request->order->getEventId());
        $sourceNamespace = $eventConfiguration['source_namespace']
            ?? $this->requiredConfigValue('services.kamp_stripe_metadata.source_namespace');
        $environment = $request->stripeEnvironment;
        if (! in_array($environment, ['test', 'live'], true)) {
            $this->fail('stripe_environment_required');
        }
        $ticketQuantity = $this->paidTicketQuantity($request);
        $this->assertBuyerFeeConservation($request, $eventConfiguration, $ticketQuantity);

        $orderRecordId = 'hi_order_record_'.$request->order->getId();
        $sourceOrderId = 'hi_order_'.$request->order->getShortId();
        $reconciliationRef = 'hi_recon_order_'.$request->order->getId();

        $metadata = [
            'kamp_university_id' => $eventConfiguration['university_id'],
            'kamp_cycle_id' => $eventConfiguration['cycle_id'],
            'kamp_event_id' => $eventConfiguration['event_id'],
            'kamp_source' => self::SOURCE,
            'kamp_schema_version' => self::SCHEMA_VERSION,
            'kamp_source_namespace' => $sourceNamespace,
            'kamp_source_record_id' => $orderRecordId,
            'kamp_source_order_id' => $sourceOrderId,
            'kamp_ticket_quantity' => (string) $ticketQuantity,
            'kamp_pricing_policy' => $eventConfiguration['pricing_policy'],
            'kamp_fee_policy' => self::FEE_POLICY,
            'kamp_environment' => $environment,
            'kamp_adapter_version' => self::ADAPTER_VERSION,
            'kamp_reconciliation_ref' => $reconciliationRef,
        ];

        if (isset($eventConfiguration['campaign_id'])) {
            $metadata['kamp_campaign_id'] = $eventConfiguration['campaign_id'];
        }
        if (isset($eventConfiguration['allocation_id'])) {
            $metadata['kamp_allocation_id'] = $eventConfiguration['allocation_id'];
        }

        $this->validateMetadata($metadata);

        $description = sprintf(
            'Kamp | %s | %s | %s | order:%s',
            $eventConfiguration['university_id'],
            $eventConfiguration['cycle_id'],
            $eventConfiguration['event_id'],
            $sourceOrderId,
        );

        if (strlen($description) > self::MAX_DESCRIPTION_LENGTH
            || preg_match(self::ASCII_PRINTABLE_PATTERN, $description) !== 1) {
            $this->fail('invalid_description');
        }

        return new KampStripeMetadataDTO(
            metadata: $metadata,
            description: $description,
            ticketQuantity: $ticketQuantity,
        );
    }

    private function assertPaymentContract(CreatePaymentIntentRequestDTO $request): void
    {
        if ($request->stripeAccountId === null || preg_match('/\Aacct_[A-Za-z0-9]+\z/D', $request->stripeAccountId) !== 1) {
            $this->fail('connected_account_required');
        }

        if (strtoupper($request->currencyCode) !== self::APPLICATION_FEE_CURRENCY
            || strtoupper($request->order->getCurrency()) !== self::APPLICATION_FEE_CURRENCY) {
            $this->fail('usd_order_required');
        }

        $accountConfiguration = $request->account->getConfiguration();
        if ($accountConfiguration === null) {
            $this->fail('account_configuration_required');
        }
        if ($accountConfiguration->getBypassApplicationFees()) {
            $this->fail('application_fee_bypass_forbidden');
        }
        if (abs($accountConfiguration->getFixedApplicationFee() - self::FIXED_APPLICATION_FEE) > 0.000001) {
            $this->fail('fixed_application_fee_mismatch');
        }
        if (abs($accountConfiguration->getPercentageApplicationFee() - self::PERCENTAGE_APPLICATION_FEE) > 0.000001) {
            $this->fail('percentage_application_fee_mismatch');
        }
        if (strtoupper($accountConfiguration->getApplicationFeeCurrency()) !== self::APPLICATION_FEE_CURRENCY) {
            $this->fail('application_fee_currency_mismatch');
        }

        $eventSettings = $request->order->getEvent()?->getEventSettings();
        if ($eventSettings === null || $eventSettings->getPassPlatformFeeToBuyer()) {
            $this->fail('generated_platform_fee_forbidden');
        }
    }

    private function paidTicketQuantity(CreatePaymentIntentRequestDTO $request): int
    {
        $orderItems = $request->order->getOrderItems();
        if ($orderItems === null || $orderItems->isEmpty()) {
            $this->fail('order_items_required');
        }

        $quantity = 0;
        foreach ($orderItems as $item) {
            if (! $item instanceof OrderItemDomainObject) {
                $this->fail('invalid_order_item');
            }
            if ($item->getPrice() < 0) {
                $this->fail('negative_item_price_forbidden');
            }
            if ($item->getPrice() === 0.0) {
                continue;
            }
            if ($item->getProductType() !== ProductType::TICKET->name) {
                $this->fail('paid_non_ticket_item_forbidden');
            }
            if ($item->getQuantity() < 1 || $quantity > 999999999 - $item->getQuantity()) {
                $this->fail('invalid_ticket_quantity');
            }
            $quantity += $item->getQuantity();
        }

        if ($quantity < 1 || $quantity > 999999999) {
            $this->fail('invalid_ticket_quantity');
        }

        return $quantity;
    }

    private function eventConfiguration(int $eventId): array
    {
        $rawMap = $this->config->get('services.kamp_stripe_metadata.event_map', []);

        try {
            $eventMap = is_string($rawMap)
                ? json_decode($rawMap, true, 512, JSON_THROW_ON_ERROR)
                : $rawMap;
        } catch (JsonException) {
            $this->fail('invalid_event_map_json');
        }

        if (! is_array($eventMap)) {
            $this->fail('invalid_event_map');
        }

        $eventConfiguration = $eventMap[(string) $eventId] ?? $eventMap[$eventId] ?? null;
        if (! is_array($eventConfiguration)) {
            $this->fail('event_not_configured');
        }

        $unknownFields = array_diff(array_keys($eventConfiguration), self::EVENT_FIELDS);
        if ($unknownFields !== []) {
            $this->fail('unknown_event_configuration_field');
        }

        foreach (['university_id', 'cycle_id', 'event_id'] as $field) {
            $this->assertSafeValue($field, $eventConfiguration[$field] ?? null, self::SAFE_ID_PATTERN);
        }
        $this->assertSafeValue('pricing_policy', $eventConfiguration['pricing_policy'] ?? null, self::SAFE_POLICY_PATTERN);
        $this->assertPositiveInteger('service_fee_id', $eventConfiguration['service_fee_id'] ?? null);
        if (array_key_exists('source_namespace', $eventConfiguration)) {
            $this->assertSafeValue('source_namespace', $eventConfiguration['source_namespace'], self::SAFE_ID_PATTERN);
        }

        foreach (['campaign_id', 'allocation_id'] as $field) {
            if (array_key_exists($field, $eventConfiguration)) {
                $this->assertSafeValue($field, $eventConfiguration[$field], self::SAFE_ID_PATTERN);
            }
        }
        if (isset($eventConfiguration['allocation_id']) && ! isset($eventConfiguration['campaign_id'])) {
            $this->fail('campaign_id_required');
        }

        return $eventConfiguration;
    }

    private function assertBuyerFeeConservation(
        CreatePaymentIntentRequestDTO $request,
        array $eventConfiguration,
        int $ticketQuantity,
    ): void {
        $expectedOrderFeeMinor = (int) round(self::FIXED_BUYER_SERVICE_FEE * 100 * $ticketQuantity);
        $expectedServiceFeeId = $eventConfiguration['service_fee_id'];
        $itemGrossMinor = 0;
        $itemTaxMinor = 0;

        foreach ($request->order->getOrderItems() as $item) {
            $rollup = $item->getTaxesAndFeesRollup() ?? [];
            if (! is_array($rollup)) {
                $this->fail('invalid_fee_rollup');
            }
            $fees = $rollup['fees'] ?? [];
            $taxes = $rollup['taxes'] ?? [];
            if (! is_array($fees) || ! is_array($taxes)) {
                $this->fail('invalid_fee_rollup');
            }

            if ($item->getPrice() === 0.0) {
                if ($fees !== [] || $taxes !== []
                    || $this->moneyMinor($item->getTotalServiceFee()) !== 0
                    || $this->moneyMinor($item->getTotalTax()) !== 0
                    || $this->moneyMinor($item->getTotalGross()) !== 0) {
                    $this->fail('free_item_addition_forbidden');
                }

                continue;
            }

            if (count($fees) !== 1) {
                $this->fail('unexpected_ticket_addition');
            }
            $serviceFee = $fees[0];
            if (! is_array($serviceFee)
                || ($serviceFee['id'] ?? null) !== $expectedServiceFeeId
                || ($serviceFee['type'] ?? null) !== TaxCalculationType::FIXED->name) {
                $this->fail('service_fee_rollup_required');
            }

            $itemExpectedFeeMinor = (int) round(self::FIXED_BUYER_SERVICE_FEE * 100 * $item->getQuantity());
            $taxMinor = $this->taxRollupMinor($taxes);
            if ($this->moneyMinor($serviceFee['value'] ?? null) !== $itemExpectedFeeMinor
                || $this->moneyMinor($serviceFee['rate'] ?? null) !== (int) round(self::FIXED_BUYER_SERVICE_FEE * 100)
                || $this->moneyMinor($item->getTotalServiceFee()) !== $itemExpectedFeeMinor
                || $this->moneyMinor($item->getTotalTax()) !== $taxMinor
                || $this->moneyMinor($item->getTotalGross()) !== $this->moneyMinor($item->getTotalBeforeAdditions()) + $itemExpectedFeeMinor + $taxMinor) {
                $this->fail('buyer_fee_amount_mismatch');
            }

            $itemGrossMinor += $this->moneyMinor($item->getTotalGross());
            $itemTaxMinor += $taxMinor;
        }

        $orderGrossMinor = $this->moneyMinor($request->order->getTotalGross());
        if ($this->moneyMinor($request->order->getTotalFee()) !== $expectedOrderFeeMinor
            || $this->moneyMinor($request->order->getTotalTax()) !== $itemTaxMinor
            || $orderGrossMinor !== $this->moneyMinor($request->order->getTotalBeforeAdditions()) + $expectedOrderFeeMinor + $itemTaxMinor
            || $orderGrossMinor !== $itemGrossMinor
            || $request->amount->toMinorUnit() !== $orderGrossMinor) {
            $this->fail('buyer_fee_amount_mismatch');
        }
    }

    private function taxRollupMinor(array $taxes): int
    {
        $total = 0;
        foreach ($taxes as $tax) {
            if (! is_array($tax)
                || ! is_int($tax['id'] ?? null)
                || $tax['id'] < 1
                || ! in_array($tax['type'] ?? null, [TaxCalculationType::FIXED->name, TaxCalculationType::PERCENTAGE->name], true)
                || (! is_int($tax['rate'] ?? null) && ! is_float($tax['rate'] ?? null))
                || ! is_finite((float) $tax['rate'])
                || $tax['rate'] < 0
                || (! is_int($tax['value'] ?? null) && ! is_float($tax['value'] ?? null))
                || ! is_finite((float) $tax['value'])
                || $tax['value'] < 0) {
                $this->fail('invalid_tax_rollup');
            }

            $total += $this->moneyMinor($tax['value']);
        }

        return $total;
    }

    private function moneyMinor(mixed $value): int
    {
        if ((! is_int($value) && ! is_float($value)) || ! is_finite((float) $value)) {
            $this->fail('invalid_money_value');
        }

        return MoneyValue::fromFloat((float) $value, self::APPLICATION_FEE_CURRENCY)->toMinorUnit();
    }

    private function requiredConfigValue(string $key): string
    {
        $value = $this->config->get($key);
        $this->assertSafeValue('source_namespace', $value, self::SAFE_ID_PATTERN);

        return $value;
    }

    private function validateMetadata(array $metadata): void
    {
        if (count($metadata) > 50) {
            $this->fail('metadata_pair_limit_exceeded');
        }

        $patterns = [
            'kamp_university_id' => self::SAFE_ID_PATTERN,
            'kamp_cycle_id' => self::SAFE_ID_PATTERN,
            'kamp_event_id' => self::SAFE_ID_PATTERN,
            'kamp_source' => self::SAFE_SOURCE_PATTERN,
            'kamp_schema_version' => self::SAFE_VERSION_PATTERN,
            'kamp_source_namespace' => self::SAFE_ID_PATTERN,
            'kamp_source_record_id' => self::SAFE_ID_PATTERN,
            'kamp_source_order_id' => self::SAFE_ID_PATTERN,
            'kamp_ticket_quantity' => '/\A[1-9][0-9]{0,8}\z/D',
            'kamp_pricing_policy' => self::SAFE_POLICY_PATTERN,
            'kamp_fee_policy' => self::SAFE_POLICY_PATTERN,
            'kamp_environment' => '/\A(?:test|live)\z/D',
            'kamp_adapter_version' => self::SAFE_VERSION_PATTERN,
            'kamp_reconciliation_ref' => self::SAFE_ID_PATTERN,
            'kamp_campaign_id' => self::SAFE_ID_PATTERN,
            'kamp_allocation_id' => self::SAFE_ID_PATTERN,
        ];

        foreach ($metadata as $key => $value) {
            if (! array_key_exists($key, $patterns) || strlen($key) > 40 || str_contains($key, '[') || str_contains($key, ']')) {
                $this->fail('invalid_metadata_key');
            }
            $this->assertSafeValue(
                $key,
                $value,
                $patterns[$key],
                in_array($key, ['kamp_ticket_quantity', 'kamp_schema_version', 'kamp_adapter_version'], true),
            );
        }
    }

    private function assertPositiveInteger(string $field, mixed $value): void
    {
        if (! is_int($value) || $value < 1) {
            $this->fail('invalid_'.$field);
        }
    }

    private function assertSafeValue(
        string $field,
        mixed $value,
        string $pattern,
        bool $allowPhoneLike = false,
    ): void {
        if (! is_string($value) || $value === '' || $value !== trim($value) || strlen($value) > 500) {
            $this->fail('invalid_'.$field);
        }
        if (preg_match(self::ASCII_PRINTABLE_PATTERN, $value) !== 1
            || preg_match(self::PRIVATE_SECRET_PATTERN, $value) === 1
            || preg_match(self::PRIVATE_LABEL_PATTERN, $value) === 1
            || (! $allowPhoneLike && preg_match(self::PHONE_LIKE_PATTERN, $value) === 1)
            || preg_match($pattern, $value) !== 1) {
            $this->fail('invalid_'.$field);
        }
    }

    private function fail(string $reason): never
    {
        throw new KampStripeMetadataConfigurationException($reason);
    }
}
