import {describe, expect, it} from 'vitest';
import {readFileSync, readdirSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {dirname, join} from 'node:path';
import {KAMP_PRIMITIVES, kamp} from './kampPrimitives';

const here = dirname(fileURLToPath(import.meta.url));
const globalScss = readFileSync(join(here, 'global.scss'), 'utf8');

/** Parse the `--kamp-*` declarations out of the `:root` block in global.scss. */
const parseKampDeclarations = (scss: string): Record<string, string> => {
    const rootStart = scss.indexOf(':root {');
    expect(rootStart, 'global.scss must contain a :root block').toBeGreaterThan(-1);

    const block = scss.slice(rootStart, scss.indexOf('\n}', rootStart));
    const out: Record<string, string> = {};

    for (const line of block.split('\n')) {
        const match = line.match(/^\s*(--kamp-[a-z0-9-]+)\s*:\s*(.+?);\s*$/);
        if (match) {
            out[match[1]] = match[2].trim();
        }
    }

    return out;
};

describe('Kamp primitive source of truth', () => {
    const declared = parseKampDeclarations(globalScss);

    it('finds the --kamp-* declarations in global.scss', () => {
        expect(Object.keys(declared).length).toBeGreaterThan(20);
    });

    it('declares every primitive in exactly the same way in SCSS and TypeScript', () => {
        // This is the guard that replaces a codegen step. If it fails, the two
        // representations have drifted and the checkout theme no longer matches
        // the event page.
        expect(declared).toEqual({...KAMP_PRIMITIVES});
    });

    it('declares --kamp-* in global.scss and nowhere else', () => {
        // Reserved-namespace rule: authored styles must not redefine a primitive.
        const srcRoot = join(here, '..');
        const files = readdirSync(srcRoot, {recursive: true, encoding: 'utf8'})
            .filter((f) => f.endsWith('.scss') || f.endsWith('.css'))
            .map((f) => join(srcRoot, f))
            .filter((f) => !f.endsWith('global.scss'));

        expect(files.length, 'expected to find stylesheets to scan').toBeGreaterThan(10);

        const offenders = files.filter((file) =>
            /^\s*--kamp-[a-z0-9-]+\s*:/m.test(readFileSync(file, 'utf8'))
        );

        expect(offenders).toEqual([]);
    });
});

describe('checkout theme derives from the primitive source', () => {
    it('exposes named accessors that resolve to the declared values', () => {
        expect(kamp.forest).toBe(declaredValue('--kamp-forest'));
        expect(kamp.cream).toBe(declaredValue('--kamp-cream'));
        expect(kamp.ink).toBe(declaredValue('--kamp-ink'));
        expect(kamp.muted).toBe(declaredValue('--kamp-muted'));
        expect(kamp.sand).toBe(declaredValue('--kamp-sand'));
        expect(kamp.fontSerif).toBe(declaredValue('--kamp-font-serif'));
        expect(kamp.fontUtility).toBe(declaredValue('--kamp-font-utility'));
    });

    function declaredValue(name: string): string {
        return parseKampDeclarations(globalScss)[name];
    }
});

describe('mutation proof', () => {
    it('propagates foundation and university roles into checkout', async () => {
        // Kamp foundation values and the resolved university adapter must both
        // cross the checkout boundary without repeated literals.
        const providerSource = readFileSync(
            join(here, '../components/layouts/Checkout/CheckoutThemeProvider.tsx'),
            'utf8'
        );
        const checkoutSource = readFileSync(
            join(here, '../components/layouts/Checkout/index.tsx'),
            'utf8'
        );

        // The light palette must be expressed in terms of the primitive accessors,
        // not as hex literals.
        const lightPalette = providerSource.slice(
            providerSource.indexOf('const LIGHT_PALETTE'),
            providerSource.indexOf('};', providerSource.indexOf('const LIGHT_PALETTE'))
        );
        expect(lightPalette).not.toMatch(/#[0-9a-fA-F]{3,8}/);
        expect(lightPalette).toMatch(/kamp\./);

        // University accents must arrive through the shared semantic resolver,
        // never as a repeated school or Kamp literal in checkout.
        expect(checkoutSource).toMatch(/resolveUniversityTheme\(event\?\.slug\)/);
        expect(checkoutSource).toMatch(/accentColor=\{universityTheme\.secondary\}/);
        expect(checkoutSource).toMatch(/accentContrastColor=\{universityTheme\.onSecondary\}/);
        expect(checkoutSource).not.toMatch(/accentColor="#/);

        // No font stack literals left in the provider.
        expect(providerSource).not.toMatch(/'PT Serif'/);
        expect(providerSource).not.toMatch(/'Lato'/);

        const eventStyles = readFileSync(
            join(here, '../components/layouts/EventHomepage/EventHomepage.module.scss'),
            'utf8'
        );
        const eventSource = readFileSync(
            join(here, '../components/layouts/EventHomepage/index.tsx'),
            'utf8'
        );
        expect(eventStyles).toMatch(/\$serif: var\(--kamp-font-serif\)/);
        expect(eventStyles).toMatch(/\$util: var\(--kamp-font-utility\)/);
        expect(eventStyles).not.toMatch(/'DM Sans'/);
        expect(eventSource).toMatch(/resolveUniversityTheme\(event\.slug\)/);
        expect(eventSource).toMatch(/'--page-brand-secondary': universityTheme\.secondary/);
        expect(eventSource).not.toMatch(/event\.id === 7/);
    });

    it('keeps mobile navigation aids from appearing during touch and overscroll', () => {
        const eventStyles = readFileSync(
            join(here, '../components/layouts/EventHomepage/EventHomepage.module.scss'),
            'utf8'
        );
        const eventSource = readFileSync(
            join(here, '../components/layouts/EventHomepage/index.tsx'),
            'utf8'
        );

        const skipLinkStyles = eventStyles.slice(
            eventStyles.indexOf('.skipLink'),
            eventStyles.indexOf('.background')
        );
        expect(skipLinkStyles).toMatch(/&:focus-visible\s*\{/);
        expect(skipLinkStyles).not.toMatch(/&:focus\s*\{/);
        expect(eventSource).toContain('shouldShowTicketScrollButton(rect.top, window.innerHeight)');
        expect(eventSource).not.toMatch(/rect\.bottom < 0/);
    });

    it('resets checkout routes to the top and prevents iPhone input zoom', () => {
        const checkoutSource = readFileSync(
            join(here, '../components/layouts/Checkout/index.tsx'),
            'utf8'
        );
        const checkoutStyles = readFileSync(
            join(here, '../components/layouts/Checkout/CheckoutContent/CheckoutContent.module.scss'),
            'utf8'
        );

        expect(checkoutSource).toContain("window.scrollTo({top: 0, left: 0, behavior: 'auto'})");
        expect(checkoutSource).toContain('}, [location.pathname]);');
        const checkoutInputRules = checkoutStyles.slice(
            checkoutStyles.lastIndexOf(':global(.mantine-Input-input)'),
            checkoutStyles.lastIndexOf('}')
        );
        expect(checkoutInputRules).toContain('font-size: 16px');

        const widgetStyles = readFileSync(join(here, 'widget/default.scss'), 'utf8');
        const widgetInputRules = widgetStyles.slice(
            widgetStyles.indexOf('.hi-donation-input'),
            widgetStyles.indexOf('.button-input button')
        );
        expect(widgetInputRules).toContain('.button-input input');
        expect(widgetInputRules).toContain('.hi-promo-code-input');
        expect(widgetInputRules).toContain('font-size: 16px');

        const mobileWidgetStyles = widgetStyles.slice(widgetStyles.indexOf('@media (max-width: 680px)'));
        expect(mobileWidgetStyles).toMatch(/\.button-input button\s*{[\s\S]*min-width: 44px/);
        expect(mobileWidgetStyles).toMatch(/grid-template-columns: minmax\(0, 1fr\) 44px/);
    });
});
