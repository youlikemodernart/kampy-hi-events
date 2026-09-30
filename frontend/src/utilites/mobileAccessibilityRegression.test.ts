import {readFileSync} from 'node:fs';
import {join} from 'node:path';
import {describe, expect, it} from 'vitest';

const srcRoot = join(process.cwd(), 'src');
const read = (path: string) => readFileSync(join(srcRoot, path), 'utf8');

describe('mobile ticket accessibility regressions', () => {
    it('keeps the named quantity controls in one stable 44px segmented group', () => {
        const component = read('components/common/NumberSelector/index.tsx');
        const styles = read('components/common/NumberSelector/NumberSelector.module.scss');
        const eventStyles = read('components/layouts/EventHomepage/EventHomepage.module.scss');

        expect(component).toContain('size={44}');
        expect(component).toContain('aria-label={t`Decrease`}');
        expect(component).toContain('aria-label={t`Ticket quantity`}');
        expect(component).toContain('aria-label={t`Increase`}');
        expect(component).toContain("classes.wrapper, classes.buttonGroup, 'button-input'");
        expect(component).toContain('classNames={{root: classes.field, input: classes.input}}');
        expect(component).toContain("classes.wrapper, classes.selectGroup, 'select-input'");
        expect(component).toContain('input: classes.selectInput');
        expect(component.indexOf('aria-label={t`Decrease`}')).toBeLessThan(component.indexOf('aria-label={t`Ticket quantity`}'));
        expect(component.indexOf('aria-label={t`Ticket quantity`}')).toBeLessThan(component.indexOf('aria-label={t`Increase`}'));
        for (const behaviorProp of [
            'min={minValue}', 'max={maxValue}', 'handlersRef={handlers}',
            'value={value}', 'onChange={changeValue}', 'onClick={decrement}', 'onClick={increment}',
        ]) {
            expect(component).toContain(behaviorProp);
        }

        expect(styles).toMatch(/\.buttonGroup\s*\{[^}]*gap:\s*var\(--space-2\)[^}]*padding:\s*var\(--space-1\)[^}]*border:\s*1px solid var\(--kamp-line-control[^}]*background:\s*var\(--kamp-paper/s);
        expect(styles).toMatch(/\.selectGroup\s*\{[^}]*width:\s*100px/s);
        expect(styles).toMatch(/\.selectInput\s*\{[^}]*padding-right:\s*10px !important[^}]*padding-left:\s*10px !important[^}]*min-width:\s*44px[^}]*height:\s*44px !important[^}]*flex:\s*1 !important[^}]*text-align:\s*center !important/s);
        expect(styles).toMatch(/\.control\s*\{[^}]*flex:\s*0 0 auto[^}]*width:\s*44px[^}]*height:\s*44px[^}]*min-width:\s*44px[^}]*min-height:\s*44px/s);
        expect(styles).toMatch(/\.field\s*\{[^}]*width:\s*3\.5rem[^}]*min-width:\s*3rem/s);
        expect(styles).toMatch(/\.input\s*\{[^}]*height:\s*44px !important[^}]*min-height:\s*44px !important[^}]*font-size:\s*1rem !important[^}]*font-variant-numeric:\s*tabular-nums lining-nums !important[^}]*border-color:\s*transparent !important[^}]*background:\s*transparent !important/s);
        expect(eventStyles).not.toMatch(/:global\(\.hi-product-quantity-selector\)\s*\{\s*\.button-input/s);
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
