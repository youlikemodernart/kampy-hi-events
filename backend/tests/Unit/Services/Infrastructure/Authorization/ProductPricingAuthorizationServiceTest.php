<?php

namespace Tests\Unit\Services\Infrastructure\Authorization;

use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\DomainObjects\TaxAndFeesDomainObject;
use HiEvents\Exceptions\UnauthorizedException;
use HiEvents\Services\Infrastructure\Authorization\ProductPricingAuthorizationService;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProductPricingAuthorizationServiceTest extends TestCase
{
    private ProductPricingAuthorizationService $service;

    private ProductDomainObject $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = new ProductPricingAuthorizationService;
        $this->product = (new ProductDomainObject)
            ->setId(10)
            ->setEventId(7)
            ->setType('PAID')
            ->setProductPrices(collect([
                (new ProductPriceDomainObject)
                    ->setId(20)
                    ->setProductId(10)
                    ->setPrice(30.00),
            ]))
            ->setTaxAndFees(collect([
                (new TaxAndFeesDomainObject)->setId(6),
            ]));
    }

    public function test_university_director_can_create_product_without_fee_associations(): void
    {
        $this->service->validateCreate(Role::UNIVERSITY_DIRECTOR, []);

        $this->addToAssertionCount(1);
    }

    public function test_university_director_cannot_create_product_with_fee_associations(): void
    {
        $this->expectException(UnauthorizedException::class);

        $this->service->validateCreate(Role::UNIVERSITY_DIRECTOR, [6]);
    }

    public function test_event_manager_can_create_product_with_fee_associations(): void
    {
        $this->service->validateCreate(Role::ORGANIZER, [6]);

        $this->addToAssertionCount(1);
    }

    public function test_university_director_can_submit_unchanged_pricing_and_fees(): void
    {
        $this->service->validateUpdate(
            Role::UNIVERSITY_DIRECTOR,
            $this->product,
            'PAID',
            [['id' => 20, 'price' => 30, 'initial_quantity_available' => 850]],
            [6],
        );

        $this->addToAssertionCount(1);
    }

    #[DataProvider('allowedPricingChanges')]
    public function test_university_director_can_change_ticket_pricing(
        string $priceType,
        array $prices,
    ): void {
        $this->service->validateUpdate(
            Role::UNIVERSITY_DIRECTOR,
            $this->product,
            $priceType,
            $prices,
            [6],
        );

        $this->addToAssertionCount(1);
    }

    public static function allowedPricingChanges(): array
    {
        return [
            'price amount' => ['PAID', [['id' => 20, 'price' => 31]]],
            'new price tier' => ['PAID', [['id' => 20, 'price' => 30], ['price' => 30]]],
            'price type' => ['FREE', [['id' => 20, 'price' => 30]]],
        ];
    }

    public function test_university_director_cannot_change_fee_associations(): void
    {
        $this->expectException(UnauthorizedException::class);

        $this->service->validateUpdate(
            Role::UNIVERSITY_DIRECTOR,
            $this->product,
            'PAID',
            [['id' => 20, 'price' => 30]],
            [],
        );
    }

    public function test_event_manager_can_change_pricing_and_fees(): void
    {
        $this->service->validateUpdate(
            Role::ORGANIZER,
            $this->product,
            'FREE',
            [['id' => 20, 'price' => 0]],
            [],
        );

        $this->addToAssertionCount(1);
    }
}
