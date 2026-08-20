<?php

namespace Tests\Unit\Services\Domain\Tax;

use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\Services\Domain\Tax\TaxAndFeeOrderRollupService;
use Illuminate\Support\Collection;
use PHPUnit\Framework\TestCase;

class TaxAndFeeOrderRollupServiceTest extends TestCase
{
    public function test_preserves_item_fee_identity_and_aggregates_matching_fee_amounts(): void
    {
        $rollup = (new TaxAndFeeOrderRollupService)->rollup(new Collection([
            $this->itemWithFee(6.00, 81),
            $this->itemWithFee(6.00, 81),
        ]));

        $this->assertSame([['id' => 81, 'name' => 'Service Fee', 'value' => 12.00, 'rate' => 6.00, 'type' => 'FIXED']], $rollup['fees']);
    }

    public function test_keeps_legacy_and_identified_rows_separate_when_legacy_is_first(): void
    {
        $rollup = (new TaxAndFeeOrderRollupService)->rollup(new Collection([
            $this->itemWithFee(1.00),
            $this->itemWithFee(6.00, 81),
            $this->itemWithFee(6.00, 81),
        ]));

        $this->assertSame([
            ['name' => 'Service Fee', 'value' => 1.00, 'rate' => 6.00, 'type' => 'FIXED'],
            ['id' => 81, 'name' => 'Service Fee', 'value' => 12.00, 'rate' => 6.00, 'type' => 'FIXED'],
        ], $rollup['fees']);
    }

    public function test_keeps_legacy_and_identified_rows_separate_when_identified_is_first(): void
    {
        $rollup = (new TaxAndFeeOrderRollupService)->rollup(new Collection([
            $this->itemWithFee(6.00, 81),
            $this->itemWithFee(1.00),
            $this->itemWithFee(2.00),
        ]));

        $this->assertSame([
            ['id' => 81, 'name' => 'Service Fee', 'value' => 6.00, 'rate' => 6.00, 'type' => 'FIXED'],
            ['name' => 'Service Fee', 'value' => 3.00, 'rate' => 6.00, 'type' => 'FIXED'],
        ], $rollup['fees']);
    }

    private function itemWithFee(float $value, ?int $id = null): OrderItemDomainObject
    {
        return (new OrderItemDomainObject)->setTaxesAndFeesRollup(['fees' => [[
            ...($id === null ? [] : ['id' => $id]),
            'name' => 'Service Fee',
            'rate' => 6.00,
            'type' => 'FIXED',
            'value' => $value,
        ]]]);
    }
}
