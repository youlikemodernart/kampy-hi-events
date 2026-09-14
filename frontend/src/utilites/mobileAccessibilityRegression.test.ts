import {readFileSync} from 'node:fs';
import {join} from 'node:path';
import {describe, expect, it} from 'vitest';

const srcRoot = join(process.cwd(), 'src');
const read = (path: string) => readFileSync(join(srcRoot, path), 'utf8');

describe('mobile ticket accessibility regressions', () => {
    it('keeps quantity controls named and at least 44px in both dimensions', () => {
        const component = read('components/common/NumberSelector/index.tsx');
        const styles = read('components/common/NumberSelector/NumberSelector.module.scss');

        expect(component).toContain('size={44}');
        expect(component).toContain('aria-label={t`Decrease`}');
        expect(component).toContain('aria-label={t`Ticket quantity`}');
        expect(component).toContain('aria-label={t`Increase`}');
        expect(styles).toMatch(/\.buttonInput\s*\{[^}]*min-width:\s*132px/s);
        expect(styles).toMatch(/\.input\s*\{[^}]*min-width:\s*44px[^}]*height:\s*44px/s);
    });

    it('reveals skip navigation only after explicit keyboard navigation', () => {
        const component = read('components/layouts/EventHomepage/index.tsx');
        const styles = read('components/layouts/EventHomepage/EventHomepage.module.scss');

        expect(component).toContain("root.dataset.kampKeyboardNavigation = 'true'");
        expect(component).toContain("window.addEventListener('pointerdown', clearKeyboardNavigation)");
        expect(styles).toMatch(/\.skipLink\s*\{[^}]*clip-path:\s*inset\(50%\)[^}]*pointer-events:\s*none/s);
        expect(styles).toContain(':global(html[data-kamp-keyboard-navigation="true"]) .skipLink:focus');
    });
});
