import React from 'react';
import {formatCurrency} from "../../../utilites/currency.ts";
import {Product, ProductPrice} from "../../../types.ts";
import {t} from "@lingui/macro";
import {IconInfoCircle} from "@tabler/icons-react";

interface CurrencyProps {
    price?: number | null | undefined;
    currency?: string;
    strikeThrough?: boolean;
    className?: string;
    freeLabel?: string | null;
}

export const Currency: React.FC<CurrencyProps> = ({
                                                      price,
                                                      currency = 'USD',
                                                      strikeThrough,
                                                      className,
                                                      freeLabel,
                                                  }) => {
    if (!price) {
        return freeLabel ? freeLabel : formatCurrency(0, currency);
    }

    let formattedPrice = <>{formatCurrency(price, currency)}</>;

    if (strikeThrough) {
        formattedPrice = <s>{formattedPrice}</s>;
    }

    return (
        <span className={className}>
            {formattedPrice}
        </span>
    );
};

interface ProductPriceProps {
    product: Product;
    price: ProductPrice;
    currency?: string;
    className?: string;
    freeLabel?: string | null;
    taxAndServiceFeeDisplayType?: 'INCLUSIVE' | 'EXCLUSIVE';
}

export const ProductPriceDisplay: React.FC<ProductPriceProps> = ({
                                                                   price,
                                                                   currency = 'USD',
                                                                   className,
                                                                   freeLabel,
                                                                   taxAndServiceFeeDisplayType = 'EXCLUSIVE',
                                                               }) => {
    const totalTaxAndFees = (price.tax_total || 0) + (price.fee_total || 0);
    const isInclusive = taxAndServiceFeeDisplayType === 'INCLUSIVE';
    const displayPrice = price.price + (isInclusive ? totalTaxAndFees : 0);

    const feeDescriptions = (price.tax_total || 0) > 0 && (price.fee_total || 0) > 0
        ? t`fees and taxes`
        : (price.tax_total || 0) > 0
            ? t`taxes`
            : t`fees`;
    const formattedBasePrice = formatCurrency(price.price, currency);
    const formattedFees = formatCurrency(totalTaxAndFees, currency);
    const formattedPrice = formatCurrency(displayPrice, currency);
    const feeSummary = isInclusive
        ? t`Includes ${formattedFees} ${feeDescriptions}`
        : t`Plus ${formattedFees} ${feeDescriptions}`;
    const priceBreakdown = isInclusive
        ? t`Base price ${formattedBasePrice}. Fees ${formattedFees}. Total ${formattedPrice}.`
        : t`Base price ${formattedBasePrice}. Fees ${formattedFees}.`;

    const appendedText = totalTaxAndFees === 0 ? null : (
        <div className="hi-price-fee-summary">
            <span>{feeSummary}</span>
            <details className="hi-price-breakdown">
                <summary className="hi-price-breakdown-trigger" aria-label={t`Show price breakdown`}>
                    <IconInfoCircle size={18} aria-hidden="true"/>
                </summary>
                <span className="hi-price-breakdown-content">{priceBreakdown}</span>
            </details>
        </div>
    );

    if (displayPrice === 0 && totalTaxAndFees === 0) {
        return <span className={className}>{freeLabel || t`Free`}</span>;
    }

    return (
        <div className={className}>
            <div>{formattedPrice}</div>
            {appendedText}
        </div>
    );
};
