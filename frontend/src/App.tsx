import React, {FC, PropsWithChildren, useCallback, useEffect} from "react";
import {MantineProvider} from "@mantine/core";
import {Notifications} from "@mantine/notifications";
import {i18n} from "@lingui/core";
import {I18nProvider} from "@lingui/react";
import {ModalsProvider} from "@mantine/modals";
import {HydrationBoundary, QueryClient, QueryClientProvider} from "@tanstack/react-query";
import {Helmet, HelmetProvider} from "react-helmet-async";
import {generateColors} from '@mantine/colors-generator';

import "@mantine/core/styles/global.css";
import "@mantine/core/styles.css";
import "@mantine/notifications/styles.css";
import "@mantine/tiptap/styles.css";
import "@mantine/dropzone/styles.css";
import '@mantine/dates/styles.css';
import "@mantine/charts/styles.css";
import "./styles/global.scss";
import {isSsr} from "./utilites/helpers.ts";
import {StartupChecks} from "./StartupChecks.tsx";
import {ThirdPartyScripts} from "./components/common/ThirdPartyScripts";
import {getConfig} from "./utilites/config.ts";
import {appName, faviconMimeType} from "./utilites/branding.ts";
import {CookieConsentBanner} from "./components/common/CookieConsentBanner";
import {kamp} from "./styles/kampPrimitives";
import {isConsentPending, setConsentState, updateGoogleConsentMode} from "./utilites/trackingPixels/consent";

declare global {
    interface Window {
        hievents: Record<string, string>;
    }
}

export const App: FC<
    PropsWithChildren<{
        queryClient: QueryClient;
        locale: string;
        helmetContext?: any;
        dehydratedState?: unknown;
    }>
> = (props) => {
    const [isLoadedOnBrowser, setIsLoadedOnBrowser] = React.useState(false);
    const showGlobalConsentBanner = getConfig('VITE_COOKIE_CONSENT_ENABLED') === 'true'
        && !isSsr() && isConsentPending();
    // public/favicon.ico exists; public/favicon.svg does not. The declared type has
    // to follow the href, or a user agent that honours it discards the icon.
    const faviconHref = getConfig("VITE_APP_FAVICON", "/favicon.ico") as string;

    const handleGlobalConsent = useCallback((granted: boolean) => {
        setConsentState(granted ? 'granted' : 'denied');
        updateGoogleConsentMode(granted);
        window.dispatchEvent(new CustomEvent('hi_consent_change', {detail: {granted}}));
    }, []);

    useEffect(() => {
        setIsLoadedOnBrowser(!isSsr());
    }, []);

    return (
        <React.StrictMode>
            <div
                className="ssr-loader"
                style={{
                    top: 0,
                    left: 0,
                    right: 0,
                    bottom: 0,
                    margin: 0,
                    padding: 0,
                    width: "100vw",
                    height: "100vh",
                    position: "fixed",
                    background: "#ffffff",
                    zIndex: 1000,
                    display: isLoadedOnBrowser ? "none" : "block",
                }}
            />
            <MantineProvider
                theme={{
                    colors: {
                        primary: generateColors(getConfig("VITE_APP_PRIMARY_COLOR", "#ff7b00") as string),
                        secondary: generateColors(getConfig("VITE_APP_SECONDARY_COLOR", kamp.ink) as string),
                    },
                    primaryColor: "primary",
                    fontFamily: kamp.fontUtility,
                    headings: {
                        fontFamily: kamp.fontUtility,
                        fontWeight: "700",
                    },
                    primaryShade: 7,
                }}
            >
                <HelmetProvider context={props.helmetContext}>
                    <I18nProvider i18n={i18n}>
                        <QueryClientProvider client={props.queryClient}>
                            <HydrationBoundary state={props.dehydratedState}>
                                <StartupChecks/>
                                <ThirdPartyScripts/>
                                <ModalsProvider>
                                    <Helmet>
                                        <title>{appName()}</title>
                                        <link rel="icon"
                                              type={faviconMimeType(faviconHref)}
                                              href={faviconHref}
                                        />
                                    </Helmet>
                                    {props.children}
                                </ModalsProvider>
                                <Notifications/>
                                {showGlobalConsentBanner && (
                                    <CookieConsentBanner onConsent={handleGlobalConsent}/>
                                )}
                            </HydrationBoundary>
                        </QueryClientProvider>
                    </I18nProvider>
                </HelmetProvider>
            </MantineProvider>
        </React.StrictMode>
    );
};
