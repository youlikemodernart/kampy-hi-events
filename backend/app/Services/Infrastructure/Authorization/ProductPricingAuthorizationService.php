<?php

namespace HiEvents\Services\Infrastructure\Authorization;

use HiEvents\DomainObjects\Enums\Permission;
use HiEvents\DomainObjects\Enums\Role;
use HiEvents\DomainObjects\ProductDomainObject;
use HiEvents\DomainObjects\ProductPriceDomainObject;
use HiEvents\Exceptions\UnauthorizedException;

readonly class ProductPricingAuthorizationService
{
    public function validateUpdate(
        Role $role,
        ProductDomainObject $product,
        string $submittedPriceType,
        array $submittedPrices,
        array $submittedTaxAndFeeIds,
    ): void {
        if ($role->hasPermission(Permission::EVENT_PRICING_MANAGE)) {
            return;
        }

        if ($product->getType() !== $submittedPriceType
            || $this->pricesChanged($product, $submittedPrices)
            || $this->taxesAndFeesChanged($product, $submittedTaxAndFeeIds)
        ) {
            throw new UnauthorizedException(__('You are not authorized to change ticket pricing or fees.'));
        }
    }

    private function pricesChanged(ProductDomainObject $product, array $submittedPrices): bool
    {
        $existingPrices = $product->getProductPrices() ?? collect();

        if ($existingPrices->count() !== count($submittedPrices)) {
            return true;
        }

        $submittedById = collect($submittedPrices)
            ->filter(static fn (array $price) => isset($price['id']))
            ->keyBy(static fn (array $price) => (int) $price['id']);

        if ($submittedById->count() !== $existingPrices->count()) {
            return true;
        }

        return $existingPrices->contains(function (ProductPriceDomainObject $existingPrice) use ($submittedById): bool {
            $submittedPrice = $submittedById->get($existingPrice->getId());

            return $submittedPrice === null
                || $this->normalizeMoney((float) $submittedPrice['price'])
                    !== $this->normalizeMoney($existingPrice->getPrice());
        });
    }

    private function taxesAndFeesChanged(ProductDomainObject $product, array $submittedTaxAndFeeIds): bool
    {
        $existingIds = ($product->getTaxAndFees() ?? collect())
            ->map(static fn ($taxAndFee) => $taxAndFee->getId())
            ->sort()
            ->values()
            ->all();

        $submittedIds = collect($submittedTaxAndFeeIds)
            ->map(static fn ($id) => (int) $id)
            ->sort()
            ->values()
            ->all();

        return $existingIds !== $submittedIds;
    }

    private function normalizeMoney(float $value): string
    {
        return number_format($value, 2, '.', '');
    }
}
