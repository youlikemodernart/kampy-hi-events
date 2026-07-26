<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Infrastructure\Financial;

use HiEvents\Exceptions\FinancialReportConfigurationException;
use HiEvents\Exceptions\ResourceNotFoundException;
use HiEvents\Services\Infrastructure\Financial\FinancialReportSourceBindingService;
use Tests\TestCase;

class FinancialReportSourceBindingServiceTest extends TestCase
{
    private FinancialReportSourceBindingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new FinancialReportSourceBindingService;
    }

    public function test_resolves_only_the_exact_server_owned_scope(): void
    {
        $this->setBindings([$this->binding()]);

        $binding = $this->service->resolve(31, 'gcu', '2026-fall');

        $this->assertSame('gcu_budget_2026', $binding->planSourceNamespace);
        $this->assertSame('spark_gcu_2026', $binding->ticketSourceNamespace);
        $this->assertSame('stripe_gcu_2026', $binding->settlementSourceNamespace);
        $this->assertSame('donorbox_gcu_2026', $binding->donationSourceNamespace);
    }

    public function test_unknown_exact_scope_is_not_found(): void
    {
        $this->setBindings([$this->binding()]);

        $this->expectException(ResourceNotFoundException::class);
        $this->service->resolve(31, 'gcu', '2027-fall');
    }

    public function test_duplicate_exact_scope_fails_closed(): void
    {
        $this->setBindings([
            $this->binding(),
            [...$this->binding(), 'plan_source_namespace' => 'other_plan'],
        ]);

        $this->expectException(FinancialReportConfigurationException::class);
        $this->expectExceptionMessage('duplicate exact scope');
        $this->service->resolve(31, 'gcu', '2026-fall');
    }

    public function test_unknown_or_malformed_binding_fields_fail_closed(): void
    {
        $this->setBindings([[
            ...$this->binding(),
            'caller_source_namespace' => 'untrusted',
        ]]);

        $this->expectException(FinancialReportConfigurationException::class);
        $this->expectExceptionMessage('invalid shape');
        $this->service->resolve(31, 'gcu', '2026-fall');
    }

    public function test_colon_bearing_scope_parts_do_not_collide(): void
    {
        $this->setBindings([
            [...$this->binding(), 'university_id' => 'a:b', 'cycle_id' => 'c'],
            [...$this->binding(), 'university_id' => 'a', 'cycle_id' => 'b:c'],
        ]);

        $binding = $this->service->resolve(31, 'a:b', 'c');

        $this->assertSame('gcu_budget_2026', $binding->planSourceNamespace);
    }

    public function test_object_and_malformed_json_fail_as_configuration_errors(): void
    {
        foreach (['{}', '{'] as $bindingsJson) {
            config(['services.kamp_financial_reports.bindings_json' => $bindingsJson]);

            try {
                $this->service->resolve(31, 'gcu', '2026-fall');
                $this->fail('Expected configuration failure.');
            } catch (FinancialReportConfigurationException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    /** @param list<array<string, int|string>> $bindings */
    private function setBindings(array $bindings): void
    {
        config([
            'services.kamp_financial_reports.bindings_json' => json_encode(
                $bindings,
                JSON_THROW_ON_ERROR,
            ),
        ]);
    }

    /** @return array<string, int|string> */
    private function binding(): array
    {
        return [
            'event_id' => 31,
            'university_id' => 'gcu',
            'cycle_id' => '2026-fall',
            'plan_source_namespace' => 'gcu_budget_2026',
            'ticket_source_namespace' => 'spark_gcu_2026',
            'settlement_source_namespace' => 'stripe_gcu_2026',
            'donation_source_namespace' => 'donorbox_gcu_2026',
        ];
    }
}
