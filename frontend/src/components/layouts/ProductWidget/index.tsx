import {useLocation, useParams} from "react-router";
import '../../../styles/widget/default.scss';
import {useGetEventPublic} from "../../../queries/useGetEventPublic.ts";
import SelectProducts from "../../routes/product-widget/SelectProducts";
import {useMemo} from "react";
import {Loader} from "@mantine/core";
import {useQuery} from "@tanstack/react-query";
import {normalizePromoCode, promoCodeClientPublic} from "../../../api/promo-code.client.ts";

const ProductWidget = () => {
    const {eventId} = useParams();
    const location = useLocation();

    const settings = useMemo(() => {
        const searchParams = new URLSearchParams(location.search);

        return {
            colors: {
                background: searchParams.get("BackgroundColor") || '#ffffff',
                primary: searchParams.get("PrimaryColor") || '#171717',
                primaryText: searchParams.get("PrimaryTextColor") || '#171717',
                secondary: searchParams.get("SecondaryColor") || '#171717',
                secondaryText: searchParams.get("SecondaryTextColor") || '#f9f4f0',
                bodyBackground: searchParams.get("BackgroundColor") || '#f9f4f0',
            },
            continueButtonText: searchParams.get("ContinueButtonText") || 'Continue',
            padding: searchParams.get("Padding") || '10px',
            promoCode: normalizePromoCode(searchParams.get("PromoCode") || searchParams.get("promo_code")),
        };
    }, [location.search]);

    const promoCodeValidationQuery = useQuery({
        queryKey: ['validateWidgetPromoCode', eventId, settings.promoCode] as const,
        queryFn: () => promoCodeClientPublic.validateCode(eventId, settings.promoCode),
        enabled: Boolean(eventId && settings.promoCode),
        retry: false,
        refetchOnWindowFocus: false,
    });

    const hasPromoCode = Boolean(settings.promoCode);
    const promoCodeValidationComplete = !hasPromoCode || promoCodeValidationQuery.isFetched;
    const isPromoCodeValid = hasPromoCode ? Boolean(promoCodeValidationQuery.data?.valid) : undefined;
    const promoCodeForEvent = isPromoCodeValid ? settings.promoCode : null;

    const eventQuery = useGetEventPublic(eventId, promoCodeValidationComplete, Boolean(promoCodeForEvent), promoCodeForEvent);

    if (!promoCodeValidationComplete || !eventQuery.isFetched || !eventQuery.data) {
        return (
            <div style={{
                display: 'flex',
                justifyContent: 'center',
                alignItems: 'center',
                height: '100vh',
                backgroundColor: settings.colors.background
            }}>
                <Loader color={settings.colors.primaryText} size="md" type="dots"/>
            </div>
        )
    }

    return (
        <div className={'full-height'} style={{backgroundColor: settings.colors.bodyBackground}}>
            <SelectProducts
                widgetMode={'embedded'}
                event={eventQuery.data}
                colors={settings.colors}
                continueButtonText={settings.continueButtonText}
                padding={settings.padding}
                promoCode={settings.promoCode || undefined}
                promoCodeValid={isPromoCodeValid}
            />
        </div>
    );
};

export default ProductWidget;
