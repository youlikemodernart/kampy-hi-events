import {describe, expect, it} from 'vitest';
import {readFileSync, readdirSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {dirname, join, relative} from 'node:path';
import {getContrastRatio} from './themeUtils';
import {kamp} from '../styles/kampPrimitives';

const here = dirname(fileURLToPath(import.meta.url));
const srcRoot = join(here, '..');
const repoRoot = join(here, '../../..');

const listFiles = (root: string, exts: string[]): string[] =>
    readdirSync(root, {recursive: true, encoding: 'utf8'})
        .filter((f) => exts.some((e) => f.endsWith(e)))
        .map((f) => join(root, f));

const read = (f: string) => readFileSync(f, 'utf8');

/** Source lines with comment-only lines removed, so explanatory prose is not scanned. */
const uncommentedLines = (source: string): string[] =>
    source
        .split('\n')
        .filter((l) => {
            const t = l.trimStart();
            return !t.startsWith('//') && !t.startsWith('*') && !t.startsWith('/*');
        });

/**
 * Files a buyer or attendee can reach without an account. Admin, auth and
 * organiser-management surfaces are out of scope for the customer-facing checks:
 * residue there is classified separately in the Phase 1 report.
 */
const CUSTOMER_FACING = [
    'components/layouts/EventHomepage',
    'components/layouts/PublicEvent',
    'components/layouts/PublicOrganizer',
    'components/layouts/OrganizerHomepage',
    'components/layouts/Checkout',
    'components/layouts/ProductWidget',
    'components/routes/product-widget',
    'components/routes/my-tickets',
    'components/common/ErrorDisplay',
    'components/common/GenericErrorPage',
    'components/common/HomepageInfoMessage',
    'components/common/ProgressStepper',
    'components/common/AttendeeTicket',
    'components/common/InlineOrderSummary',
    'components/common/BrandMark',
    'components/common/CheckoutDocumentHead',
    'components/common/EventDocumentHead',
];

const customerFacingFiles = () =>
    listFiles(srcRoot, ['.tsx', '.ts', '.scss'])
        .filter((f) => CUSTOMER_FACING.some((dir) => f.includes(join(srcRoot, dir))))
        .filter((f) => !f.endsWith('.test.ts') && !f.endsWith('.test.tsx'));

describe('customer-facing surfaces carry no default Hi.Events identity', () => {
    const files = customerFacingFiles();

    it('has customer-facing files to scan', () => {
        expect(files.length).toBeGreaterThan(20);
    });

    it('never falls back to an upstream logo asset', () => {
        const offenders = files
            .filter((f) => /["'`]\/logos\/hi-events-/.test(read(f)))
            .map((f) => relative(repoRoot, f));

        expect(offenders).toEqual([]);
    });

    it('never falls back to the upstream product name', () => {
        // Licence notices in comments are permitted and required; only executable
        // string defaults are checked.
        const offenders = files
            .filter((f) =>
                uncommentedLines(read(f)).some((l) =>
                    /getConfig\(\s*["']VITE_APP_NAME["']\s*,\s*["']Hi\.Events["']/.test(l)
                )
            )
            .map((f) => relative(repoRoot, f));

        expect(offenders).toEqual([]);
    });

    it('never routes a buyer to an upstream support address', () => {
        const offenders = files
            .filter((f) => /hello@hi\.events|support@hi\.events/.test(read(f)))
            .map((f) => relative(repoRoot, f));

        expect(offenders).toEqual([]);
    });

    it('specifies no typeface that the application does not load', () => {
        // DM Sans is the single loaded customer-facing family. 'Outfit' was previously
        // specified in two checkout components and silently fell back to a system sans.
        const loaded = read(join(srcRoot, 'styles/global.scss'));
        expect(loaded).toMatch(/font-family: 'DM Sans'/);
        expect(loaded).not.toMatch(/font-family: 'PT Serif'/);
        expect(loaded).not.toMatch(/font-family: 'Lato'/);

        const offenders = files
            .filter((f) => uncommentedLines(read(f)).some((l) => /['"]Outfit['"]/.test(l)))
            .map((f) => relative(repoRoot, f));

        expect(offenders).toEqual([]);
    });

    it('uses no decorative gradients or transparent text fills on customer surfaces', () => {
        // The palette is declared as broad material bands with no gradients, and a
        // transparent text fill can leave a heading invisible under forced-colors.
        //
        // Exempt: EventHomepage .heroGradient is an ink scrim over the cover
        // photograph that keeps the event title legible. That is a contrast device,
        // not brand decoration, and removing it would hurt readability.
        const GRADIENT_EXEMPT = ['components/layouts/EventHomepage/EventHomepage.module.scss'];

        const gradientOffenders = files
            .filter((f) => f.endsWith('.scss'))
            .filter((f) => !GRADIENT_EXEMPT.some((e) => f.endsWith(e)))
            .filter((f) => /linear-gradient|radial-gradient/.test(read(f)))
            .map((f) => relative(repoRoot, f));
        expect(gradientOffenders).toEqual([]);

        // The exemption is narrow: the only gradient permitted in that file is the
        // hero scrim, and it must stay a neutral ink ramp rather than a brand ramp.
        const eventHomepage = read(join(srcRoot, GRADIENT_EXEMPT[0]));
        const gradients = eventHomepage.match(/(linear|radial)-gradient/g) ?? [];
        expect(gradients.length).toBe(1);
        expect(eventHomepage).toMatch(/\.heroGradient\s*\{[^}]*linear-gradient/);

        const fillOffenders = files
            .filter((f) => /-webkit-text-fill-color:\s*transparent/.test(read(f)))
            .map((f) => relative(repoRoot, f));
        expect(fillOffenders).toEqual([]);

        const mantineGradientOffenders = files
            .filter((f) => /variant="gradient"/.test(read(f)))
            .map((f) => relative(repoRoot, f));
        expect(mantineGradientOffenders).toEqual([]);
    });

    it('uses no off-palette default accent on the ticket artifact', () => {
        const ticket = read(join(srcRoot, 'components/common/AttendeeTicket/index.tsx'));
        expect(ticket).not.toMatch(/#6B46C1/i);
        expect(ticket).toMatch(/accent_color \|\| kamp\.forest/);
    });
});

describe('transactional surfaces declare their own document identity', () => {
    it('titles each transactional route by the event, not the application name', () => {
        const routes = [
            'components/routes/product-widget/CollectInformation/index.tsx',
            'components/routes/product-widget/Payment/index.tsx',
            'components/routes/product-widget/PaymentReturn/index.tsx',
            'components/routes/product-widget/OrderSummaryAndProducts/index.tsx',
            'components/routes/product-widget/AttendeeProductAndInformation/index.tsx',
            'components/routes/product-widget/PrintOrder/index.tsx',
            'components/routes/my-tickets/index.tsx',
        ];

        const missing = routes.filter((r) => !read(join(srcRoot, r)).includes('CheckoutDocumentHead'));
        expect(missing).toEqual([]);
    });

    it('honours the per-event search-indexing setting on the event page', () => {
        const head = read(join(srcRoot, 'components/common/EventDocumentHead/index.tsx'));
        expect(head).toMatch(/allow_search_engine_indexing/);
        expect(head).not.toMatch(/content="index, follow"/);
    });
});

describe('licence attribution is retained and legible', () => {
    const footer = read(join(srcRoot, 'components/common/PoweredByFooter/index.tsx'));
    const footerStyles = read(
        join(srcRoot, 'components/common/PoweredByFooter/FloatingPoweredBy.module.scss'),
    );

    it('still renders the notice', () => {
        // Subordination is by order, scale and colour only. Removing the notice is a
        // licensing decision, not a styling one.
        expect(footer).toMatch(/Powered by/);
        expect(footer).toMatch(/Hi\.Events/);
    });

    it('is never hidden from assistive technology', () => {
        expect(footerStyles).not.toMatch(/display:\s*none/);
        expect(footerStyles).not.toMatch(/visibility:\s*hidden/);
        expect(footer).not.toMatch(/aria-hidden/);
    });

    it('keeps the link distinguishable without relying on colour', () => {
        expect(footerStyles).toMatch(/text-decoration:\s*underline/);
    });

    it('is set no smaller than the 13px legibility floor', () => {
        const sizes = [...footerStyles.matchAll(/font-size:\s*([\d.]+)rem/g)].map((m) => Number(m[1]));
        expect(sizes.length).toBeGreaterThan(0);
        for (const size of sizes) {
            expect(size).toBeGreaterThanOrEqual(0.8125);
        }
    });

    it('meets the normal-text contrast threshold against the page ground', () => {
        expect(getContrastRatio(kamp.muted, kamp.cream)).toBeGreaterThanOrEqual(4.5);
        expect(getContrastRatio(kamp.muted, kamp.paper)).toBeGreaterThanOrEqual(4.5);
    });

    it('calls its hook before the licence early-return', () => {
        // A conditional hook throws "Rendered fewer hooks than expected" if the SSR
        // and client config sources ever disagree, on every surface that mounts it.
        const hookAt = footer.indexOf('useMemo');
        const returnAt = footer.indexOf('iHavePurchasedALicence()');
        expect(hookAt).toBeGreaterThan(-1);
        expect(returnAt).toBeGreaterThan(hookAt);
    });
});

describe('checkout state colours meet the normal-text contrast threshold', () => {
    it('renders an inactive progress step legibly', () => {
        // The inactive step previously paired --checkout-border (#8a8a8a) behind
        // --checkout-text-secondary (#585254): 2.21:1 against a 4.5:1 requirement.
        expect(getContrastRatio(kamp.ink, kamp.sand)).toBeGreaterThanOrEqual(4.5);
        expect(getContrastRatio(kamp.muted, kamp.lineControl)).toBeLessThan(4.5);
    });

    it('renders the active progress step legibly', () => {
        expect(getContrastRatio(kamp.paper, kamp.forest)).toBeGreaterThanOrEqual(4.5);
    });
});

describe('regression guards for the Phase 1 review findings', () => {
    it('F1: keeps a screen-visible attribution on both print routes', () => {
        // .poweredByInTicket is display:none on screen and display:block in print;
        // .webOnlyFooter is the inverse. They are complementary media, not
        // duplicates. Removing the screen instance left the public print-order
        // route with no rendered notice at all in a browser.
        for (const route of ['PrintOrder', 'PrintProduct']) {
            const source = read(join(srcRoot, `components/routes/product-widget/${route}/index.tsx`));
            expect(source, `${route} must render a screen-visible attribution`)
                .toMatch(/classes\.webOnlyFooter/);
            expect(source, `${route} must render PoweredByFooter`)
                .toMatch(/<PoweredByFooter\s*\/>/);
        }

        const ticketStyles = read(join(srcRoot, 'components/common/AttendeeTicket/AttendeeTicket.module.scss'));
        expect(ticketStyles).toMatch(/\.poweredByInTicket\s*\{[^}]*display:\s*none/s);

        const printOrderStyles = read(join(srcRoot, 'components/routes/product-widget/PrintOrder/PrintOrder.module.scss'));
        expect(printOrderStyles).toMatch(/\.webOnlyFooter\s*\{[^}]*@media print\s*\{[^}]*display:\s*none/s);
    });

    it('F5: the support chain contains no term the public payload cannot supply', () => {
        // OrganizerResourcePublic exposes no `email`, so an `organizer.email` term
        // is always undefined on customer surfaces and silently does nothing.
        const branding = read(join(srcRoot, 'utilites/branding.ts'));
        const fn = branding.slice(branding.indexOf('export const eventSupportEmail'));

        expect(fn).not.toMatch(/organizer\?\.email/);
        expect(fn).toMatch(/settings\?\.support_email/);
        expect(fn).toMatch(/platformSupportEmail\(\)/);
    });

    it('F5: every terminal state offers a route even with no support address', () => {
        const terminalStates: Array<[string, RegExp]> = [
            ['components/routes/product-widget/OrderSummaryAndProducts/index.tsx', /Order Not Found/],
            ['components/routes/product-widget/AttendeeProductAndInformation/index.tsx', /Ticket Not Found/],
            ['components/routes/product-widget/PaymentReturn/index.tsx', /unable to confirm your payment/],
        ];

        for (const [file, marker] of terminalStates) {
            const source = read(join(srcRoot, file));
            const at = source.search(marker);
            expect(at, `${file}: marker not found`).toBeGreaterThan(-1);

            // The HomepageInfoMessage block around the marker must carry a link.
            const block = source.slice(Math.max(0, at - 400), at + 400);
            expect(block, `${file}: terminal state must offer a navigational route`)
                .toMatch(/linkText=/);
        }
    });

    it('F7: no customer or admin surface renders an img whose src can be undefined', () => {
        // appLogoLight()/appLogoDark() return undefined when unconfigured, which is
        // the default here. Passing that to <img src> renders a broken image;
        // BrandMark degrades to a styled wordmark instead.
        const all = listFiles(srcRoot, ['.tsx']).filter((f) => !f.endsWith('.test.tsx'));
        const offenders = all
            .filter((f) => /src=\{appLogo(Light|Dark)\(\)/.test(read(f)))
            .map((f) => relative(repoRoot, f));

        expect(offenders).toEqual([]);
    });

    it('F8: the favicon type attribute follows its href', () => {
        const app = read(join(srcRoot, 'App.tsx'));
        expect(app).toMatch(/type=\{faviconMimeType\(/);
        expect(app).not.toMatch(/type="image\/svg\+xml"/);
    });

    it('F9: the shared state component is the page heading, not a section label', () => {
        // It is what renders for RESERVED and ABANDONED orders on the summary route,
        // which the order-status guard sends here before WelcomeHeader is reached.
        const info = read(join(srcRoot, 'components/common/HomepageInfoMessage/index.tsx'));
        expect(info).toMatch(/<h1 className=\{classes\.title\}>/);
        expect(info).not.toMatch(/<h2 className=\{classes\.title\}>/);
    });
});
