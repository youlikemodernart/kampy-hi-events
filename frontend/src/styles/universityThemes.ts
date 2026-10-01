import universityThemeManifest from './universityThemes.json';

export interface UniversityTheme {
    primary: string;
    secondary: string;
    onPrimary: string;
    onSecondary: string;
    secondarySoft: string;
    heroImage?: string;
}

export const KAMP_FALLBACK_KEY = '_kampFallback';
const UNIVERSITY_THEMES: Readonly<Record<string, UniversityTheme>> = universityThemeManifest;
export const KAMP_FALLBACK_THEME: UniversityTheme = UNIVERSITY_THEMES[KAMP_FALLBACK_KEY];

export function resolveUniversityTheme(slug?: string | null): UniversityTheme {
    if (!slug || slug === KAMP_FALLBACK_KEY) return KAMP_FALLBACK_THEME;
    return UNIVERSITY_THEMES[slug] ?? KAMP_FALLBACK_THEME;
}
