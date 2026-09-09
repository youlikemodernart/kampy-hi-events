import {describe, expect, it} from 'vitest';
import {kamp} from './kampPrimitives';
import {KAMP_FALLBACK_THEME, resolveUniversityTheme} from './universityThemes';

function contrastRatio(foreground: string, background: string): number {
    const luminance = (hex: string) => {
        const channels = hex.slice(1).match(/.{2}/g)!.map((value) => {
            const channel = parseInt(value, 16) / 255;
            return channel <= 0.04045 ? channel / 12.92 : ((channel + 0.055) / 1.055) ** 2.4;
        });
        return 0.2126 * channels[0] + 0.7152 * channels[1] + 0.0722 * channels[2];
    };

    const a = luminance(foreground);
    const b = luminance(background);
    return (Math.max(a, b) + 0.05) / (Math.min(a, b) + 0.05);
}

describe('university theme adapters', () => {
    it('resolves GVSU colors through semantic theme roles', () => {
        expect(resolveUniversityTheme('grand-valley-state-university')).toEqual({
            primary: '#0032a0',
            secondary: '#13155c',
            onPrimary: '#ffffff',
            onSecondary: '#ffffff',
            secondarySoft: '#e7e7ed',
        });
    });

    it('requires readable foregrounds for both GVSU brand fields', () => {
        const theme = resolveUniversityTheme('grand-valley-state-university');
        expect(contrastRatio(theme.onPrimary, theme.primary)).toBeGreaterThanOrEqual(4.5);
        expect(contrastRatio(theme.onSecondary, theme.secondary)).toBeGreaterThanOrEqual(4.5);
    });

    it('uses the Kamp theme when no university adapter exists', () => {
        expect(resolveUniversityTheme('unknown-school')).toBe(KAMP_FALLBACK_THEME);
        expect(KAMP_FALLBACK_THEME).toMatchObject({
            primary: kamp.forest,
            secondary: kamp.forestDeep,
            secondarySoft: '#e9efe9',
        });
    });
});
