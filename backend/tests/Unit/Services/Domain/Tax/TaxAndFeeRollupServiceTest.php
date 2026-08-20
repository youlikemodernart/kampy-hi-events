<?php

namespace Tests\Unit\Services\Domain\Tax;

use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\Services\Domain\Tax\TaxAndFeeRollupService;
use PHPUnit\Framework\TestCase;

class TaxAndFeeRollupServiceTest extends TestCase
{
    private function fee(int $id, string $name, float $rate): TaxAndFeesDomainObject
    {
        return (new TaxAndFeesDomainObject)
            ->setId($id)
            ->setName($name)
            ->setRate($rate)
            ->setCalculationType('FIXED')
            ->setType('FEE');
    }

    public function test_preserves_the_configured_fee_identity_in_the_rollup(): void
    {
        $service = new TaxAndFeeRollupService;
        $service->addToRollUp($this->fee(81, 'Service Fee', 6.00), 6.00);

        $this->assertSame([
            'fees' => [[
                'id' => 81,
                'name' => 'Service Fee',
                'rate' => 6.00,
                'type' => 'FIXED',
                'value' => 6.00,
            ]],
        ], $service->getRollUp());
    }

    public function test_does_not_merge_different_fee_identities_that_share_a_name(): void
    {
        $service = new TaxAndFeeRollupService;
        $service->addToRollUp($this->fee(81, 'Service Fee', 6.00), 6.00);
        $service->addToRollUp($this->fee(82, 'Service Fee', 1.00), 1.00);

        $this->assertSame([81, 82], array_column($service->getRollUp()['fees'], 'id'));
    }
}
