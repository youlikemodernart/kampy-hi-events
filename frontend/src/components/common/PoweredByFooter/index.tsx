import {t} from "@lingui/macro";
import classes from "./FloatingPoweredBy.module.scss";
import classNames from "classnames";
import React, {useMemo} from "react";
import {iHavePurchasedALicence} from "../../../utilites/helpers.ts";
import {getConfig} from "../../../utilites/config.ts";

/**
 * (c) Hi.Events Ltd 2025
 *
 * PLEASE NOTE:
 *
 * Hi.Events is licensed under the GNU Affero General Public License (AGPL) version 3.
 *
 * You can find the full license text at: https://github.com/HiEventsDev/hi.events/blob/main/LICENCE
 *
 * In accordance with Section 7(b) of the AGPL, you must retain the "Powered by Hi.Events" notice.
 *
 * If you wish to remove this notice, a commercial license is available at: https://hi.events/licensing
 */
export const PoweredByFooter = (
    props: React.DetailedHTMLProps<React.HTMLAttributes<HTMLDivElement>, HTMLDivElement>
) => {
    // The hook must run before any conditional return. It previously sat after the
    // licence early-return, so the licensed and unlicensed paths rendered a
    // different number of hooks. getConfig reads process.env during SSR and
    // window.hievents on the client, so a disagreement between those two sources
    // would throw "Rendered fewer hooks than expected" during hydration on every
    // surface that mounts this footer.
    const link = useMemo(() => {
        let host = getConfig("VITE_FRONTEND_URL") ?? "unknown";
        let medium = "app";

        if (typeof window !== "undefined" && window.location) {
            host = window.location.hostname;
            medium = window.location.pathname.includes("/widget") ? "widget" : "app";
        }

        const url = new URL("https://hi.events");
        url.searchParams.set("utm_source", "app-powered-by-footer");
        url.searchParams.set("utm_medium", 'self-hosted-' + medium);
        url.searchParams.set("utm_campaign", "powered-by");
        url.searchParams.set("utm_content", host);

        return url.toString();
    }, []);

    if (iHavePurchasedALicence()) {
        return <></>;
    }

    // Always the attribution. Upstream swapped this for a "Try Hi.Events Free"
    // marketing CTA whenever the host contained ".hi.events"; on a Kamp Love
    // deployment that would both drop the licence notice and advertise another
    // ticketing product to a buyer. Rendering the notice unconditionally is the
    // conservative behaviour on both counts.
    const footerContent = (
        <>
            {t`Powered by`}{" "}
            <a
                href={link}
                target="_blank"
                title={"Effortlessly manage events and sell tickets online with Hi.Events"}
            >
                Hi.Events
            </a>
        </>
    );

    return (
        <div {...props} className={classNames(classes.poweredBy, props.className)}>
            <div className={classes.poweredByText}>
                {footerContent}
            </div>
        </div>
    );
}
