import {describe, expect, it} from 'vitest';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {dirname, join} from 'node:path';

const here = dirname(fileURLToPath(import.meta.url));
const globalScss = readFileSync(join(here, 'global.scss'), 'utf8');
const checkoutContentScss = readFileSync(
    join(here, '../components/layouts/Checkout/CheckoutContent/CheckoutContent.module.scss'),
    'utf8',
);

describe('checkout control targets', () => {
    it('preserves the existing minimum hitbox outside checkout', () => {
        expect(globalScss).toMatch(/button, \[role='button'\], input, select\s*\{\s*min-height: 44px;/);
        expect(checkoutContentScss).toMatch(/\.main\s*\{/);
        expect(checkoutContentScss).toMatch(/:global\(\.mantine-Radio-radio\),\s*:global\(\.mantine-Checkbox-input\)\s*\{\s*min-height: 0;/);
    });

    it('enlarges checkout labels rather than the indicator', () => {
        expect(checkoutContentScss).toMatch(/\.mantine-Checkbox-label\)\s*\{\s*padding-block:\s*calc\(\(44px - var\(--label-lh, 20px\)\) \/ 2\)/);
        expect(checkoutContentScss).not.toMatch(/\.mantine-Checkbox-input\)\s*\{[^}]*[;{\s](width|height)\s*:/);
    });
});
