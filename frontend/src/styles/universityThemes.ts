import {kamp} from './kampPrimitives';
import universityThemeManifest from './universityThemes.json';

export interface UniversityTheme {
    primary: string;
    secondary: string;
    onPrimary: string;
    onSecondary: string;
    secondarySoft: string;
    heroImage?: string;
}

const UNIVERSITY_THEMES: Readonly<Record<string, UniversityTheme>> = universityThemeManifest;

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
