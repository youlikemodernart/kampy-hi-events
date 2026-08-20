<?php

namespace HiEvents\Services\Domain\Tax;

use Illuminate\Support\Collection;

class TaxAndFeeOrderRollupService
{
    public function rollup(Collection $orderItems): array
    {
        $orderRollup = [];

        foreach ($orderItems as $orderItem) {
            $itemTaxRollUp = $orderItem->getTaxesAndFeesRollup();

            foreach ($itemTaxRollUp as $type => $taxesAndFees) {
                $orderRollup[$type] ??= [];

                foreach ($taxesAndFees as $taxOrFee) {
                    $hasIdentity = is_int($taxOrFee['id'] ?? null) && $taxOrFee['id'] > 0;
                    $foundIndex = null;
                    foreach ($orderRollup[$type] as $index => $existingTaxOrFee) {
                        $existingHasIdentity = is_int($existingTaxOrFee['id'] ?? null) && $existingTaxOrFee['id'] > 0;
                        $sameIdentity = $hasIdentity
                            ? $existingHasIdentity && $existingTaxOrFee['id'] === $taxOrFee['id']
                            : ! $existingHasIdentity && $existingTaxOrFee['name'] === $taxOrFee['name'];

                        if ($sameIdentity) {
                            $foundIndex = $index;
                            break;
                        }
                    }

                    if ($foundIndex === null) {
                        $orderRollup[$type][] = [
                            ...($hasIdentity ? ['id' => $taxOrFee['id']] : []),
                            'name' => $taxOrFee['name'],
                            'value' => $taxOrFee['value'],
                            'rate' => $taxOrFee['rate'],
                            'type' => $taxOrFee['type'],
                        ];
                    } else {
                        $orderRollup[$type][$foundIndex]['value'] += $taxOrFee['value'];
                    }
                }
            }
        }

        return $orderRollup;
    }
}
