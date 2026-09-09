import {kamp} from './kampPrimitives';

export interface UniversityTheme {
    primary: string;
    secondary: string;
    onPrimary: string;
    onSecondary: string;
    secondarySoft: string;
    heroImage?: string;
}

/**
 * University-owned visual adapters. Kamp owns the white foundation, typography,
 * geometry, interaction patterns, and fallback. Public components consume
 * semantic page roles produced from this registry, never school-specific names.
 */
const UNIVERSITY_THEMES: Readonly<Record<string, UniversityTheme>> = {
    'grand-valley-state-university': {
        primary: '#0032a0',
        secondary: '#13155c',
        onPrimary: '#ffffff',
        onSecondary: '#ffffff',
        secondarySoft: '#e7e7ed',
    },
};

export const KAMP_FALLBACK_THEME: UniversityTheme = {
    primary: kamp.forest,
    secondary: kamp.forestDeep,
    onPrimary: kamp.paper,
    onSecondary: kamp.paper,
    secondarySoft: '#e9efe9',
};

export function resolveUniversityTheme(slug?: string | null): UniversityTheme {
    if (!slug) return KAMP_FALLBACK_THEME;
    return UNIVERSITY_THEMES[slug] ?? KAMP_FALLBACK_THEME;
}
