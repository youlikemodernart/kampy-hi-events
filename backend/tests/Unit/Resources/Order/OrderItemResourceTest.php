<?php

namespace Tests\Unit\Resources\Order;

use HiEvents\DomainObjects\OrderItemDomainObject;
use HiEvents\Resources\Order\OrderItemResource;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

class OrderItemResourceTest extends TestCase
{
    public function test_exposes_rollup_identity_without_changing_item_totals(): void
    {
        $item = (new OrderItemDomainObject)
            ->setId(1)->setOrderId(2)->setTotalBeforeAdditions(100.00)->setPrice(100.00)
            ->setQuantity(1)->setProductId(3)->setItemName('Ticket')->setPriceBeforeDiscount(100.00)
            ->setTaxesAndFeesRollup(['fees' => [[
                'id' => 81, 'name' => 'Service Fee', 'rate' => 6.00, 'type' => 'FIXED', 'value' => 6.00,
            ]]]);

        $resource = (new OrderItemResource($item))->toArray(Request::create('/'));

        $this->assertSame(81, $resource['taxes_and_fees_rollup']['fees'][0]['id']);
        $this->assertSame(100.00, $resource['total_before_additions']);
        $this->assertSame(100.00, $resource['price']);
    }
}
