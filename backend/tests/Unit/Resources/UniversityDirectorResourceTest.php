<?php

namespace Tests\Unit\Resources;

use HiEvents\DomainObjects\AccountDomainObject;
use HiEvents\DomainObjects\EventSettingDomainObject;
use HiEvents\Resources\Account\UniversityDirectorAccountResource;
use HiEvents\Resources\Event\UniversityDirectorEventSettingsResource;
use Illuminate\Http\Request;
use Tests\TestCase;

class UniversityDirectorResourceTest extends TestCase
{
    public function test_account_projection_excludes_provider_and_fee_configuration(): void
    {
        $account = (new AccountDomainObject)
            ->setId(1)
            ->setName('Kamp Love')
            ->setCurrencyCode('USD')
            ->setTimezone('America/New_York')
            ->setUpdatedAt('2026-09-01 00:00:00')
            ->setAccountVerifiedAt('2026-09-01 00:00:00')
            ->setIsManuallyVerified(true);

        $data = (new UniversityDirectorAccountResource($account))->toArray(Request::create('/'));

        foreach (['stripe_account_id', 'stripe_account_details', 'stripe_platform', 'configuration'] as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }

        $this->assertSame('Kamp Love', $data['name']);
        $this->assertSame('USD', $data['currency_code']);
    }

    public function test_event_settings_projection_excludes_payment_invoicing_and_platform_fee_fields(): void
    {
        $settings = new EventSettingDomainObject;
        $data = (new UniversityDirectorEventSettingsResource($settings))->toArray(Request::create('/'));

        foreach ([
            'payment_providers',
            'offline_payment_instructions',
            'allow_orders_awaiting_offline_payment_to_check_in',
            'enable_invoicing',
            'invoice_label',
            'invoice_prefix',
            'invoice_start_number',
            'require_billing_address',
            'organization_name',
            'organization_address',
            'invoice_tax_details',
            'invoice_notes',
            'invoice_payment_terms_days',
            'pass_platform_fee_to_buyer',
        ] as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }

        $this->assertArrayHasKey('support_email', $data);
        $this->assertArrayHasKey('location_details', $data);
    }
}
