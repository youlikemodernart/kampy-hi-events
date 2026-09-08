import {getConfig} from "./config.ts";
import {
    KAMP_BRAND_NAME,
    KAMP_PRIVACY_URL,
    KAMP_SITE_URL,
    KAMP_TERMS_URL,
} from "../styles/kampPrimitives.ts";

/**
 * Single source for customer-visible brand identity.
 *
 * Upstream Hi.Events scattered `getConfig("VITE_APP_NAME", "Hi.Events")` and
 * `getConfig("VITE_APP_LOGO_*", "/logos/hi-events-*.svg")` across ~14 call sites,
 * so an unconfigured deployment shipped Hi.Events identity to buyers. Everything
 * here defaults to Kamp Love instead, and the logo accessors return `undefined`
 * rather than an upstream asset path when unset.
 */

export const appName = (): string =>
    getConfig("VITE_APP_NAME", KAMP_BRAND_NAME) as string;

/**
 * Logo for use on light backgrounds. Returns `undefined` when unconfigured: there
 * is no Kamp Love logo asset in this repository, and falling back to the Hi.Events
 * mark is worse than falling back to a text wordmark.
 *
 * Do not pass the result straight to an `<img src>`: React omits the attribute and
 * renders a broken image. Use `<BrandMark/>`, which degrades to a styled wordmark.
 */
export const appLogoLight = (): string | undefined =>
    getConfig("VITE_APP_LOGO_LIGHT") || undefined;

/** Logo for use on dark backgrounds. Returns `undefined` when unconfigured. See appLogoLight. */
export const appLogoDark = (): string | undefined =>
    getConfig("VITE_APP_LOGO_DARK") || undefined;

/**
 * MIME type for a favicon href. The declared type must follow the href, or a user
 * agent that honours it discards the icon as malformed.
 */
export const faviconMimeType = (href: string): string => {
    if (href.endsWith('.svg')) return 'image/svg+xml';
    if (href.endsWith('.png')) return 'image/png';
    return 'image/x-icon';
};

export const privacyUrl = (): string =>
    getConfig("VITE_PRIVACY_URL", KAMP_PRIVACY_URL) as string;

export const termsUrl = (): string =>
    getConfig("VITE_TOS_URL", KAMP_TERMS_URL) as string;

/** Where a customer with no event context should be sent. Never the admin login. */
export const brandSiteUrl = (): string =>
    getConfig("VITE_BRAND_SITE_URL", KAMP_SITE_URL) as string;

export const platformSupportEmail = (): string | undefined =>
    getConfig("VITE_PLATFORM_SUPPORT_EMAIL") || undefined;

/**
 * Customer support address for an event, most specific first.
 *
 * Resolves `event.settings.support_email`, then `VITE_PLATFORM_SUPPORT_EMAIL`.
 * Returns `undefined` when neither is set, and callers must handle that: a support
 * line is shown only when an address actually exists.
 *
 * There is deliberately no `organizer.email` term. The backend mailers do fall
 * back to it (`getSupportEmail() ?: $organizer->getEmail()`), but that address is
 * not, and should not be, in the public payload: `OrganizerResourcePublic` exposes
 * id, name, website, description, slug, status, images, events and settings, and
 * adding the organiser's account address would publish it on every printed ticket.
 * An earlier version of this function read `event.organizer.email`, which is always
 * undefined on customer surfaces and so silently did nothing.
 *
 * Consequence to know: the two channels can legitimately disagree. An event with no
 * `support_email` still gets a working reply route in email, and shows no address on
 * the page. Every caller therefore also offers a navigational route back to the
 * event, so a buyer is never left with no next step.
 */
export const eventSupportEmail = (event?: {
    settings?: { support_email?: string } | null;
} | null): string | undefined =>
    event?.settings?.support_email || platformSupportEmail();
