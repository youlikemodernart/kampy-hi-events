/**
 * Canonical Kamp Love primitive values.
 *
 * `frontend/src/styles/global.scss` remains the runtime source of truth for the
 * `--kamp-*` custom properties. This module exists because a small number of
 * consumers need the *resolved value* rather than a `var()` reference:
 * Mantine theme objects and the checkout accent arithmetic in
 * `CheckoutThemeProvider` cannot operate on `var(--kamp-forest)`.
 *
 * The two are kept in agreement by `kampPrimitives.test.ts`, which parses the
 * `:root` block in global.scss and asserts an exact match. There is deliberately
 * no codegen step: a static test buys the same guarantee without adding a build
 * lifecycle to own.
 *
 * Adding a primitive means adding it in BOTH places. The test will tell you.
 */

export const KAMP_PRIMITIVES = {
    '--kamp-font-serif': "'DM Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
    '--kamp-font-utility': "'DM Sans', -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif",
    '--kamp-cream': '#ffffff',
    '--kamp-paper': '#ffffff',
    '--kamp-ink': '#222222',
    '--kamp-muted': '#585254',
    '--kamp-line': '#dadada',
    '--kamp-line-control': '#8a8a8a',
    '--kamp-orange': '#ff7b00',
    '--kamp-orange-tint': '#fdeede',
    '--kamp-ok': '#2f6b4f',
    '--kamp-ok-tint': '#e7f0ea',
    '--kamp-wait': '#3e5dac',
    '--kamp-wait-tint': '#e9edf2',
    '--kamp-stop': '#9f3620',
    '--kamp-stop-tint': '#f5e2dc',
    '--kamp-forest': '#2b663d',
    '--kamp-forest-deep': '#1f4a2d',
    '--kamp-university-gvsu': '#0032a0',
    '--kamp-moss': '#5c6551',
    '--kamp-clay': '#b3654a',
    '--kamp-clay-tint': '#efe0d8',
    '--kamp-sky': '#9db3ba',
    '--kamp-wood': '#6f5f4e',
    '--kamp-sand': '#ffffff',
} as const;

export type KampPrimitiveName = keyof typeof KAMP_PRIMITIVES;

/** Named accessors for the values consumed from TypeScript. */
export const kamp = {
    fontSerif: KAMP_PRIMITIVES['--kamp-font-serif'],
    fontUtility: KAMP_PRIMITIVES['--kamp-font-utility'],
    cream: KAMP_PRIMITIVES['--kamp-cream'],
    paper: KAMP_PRIMITIVES['--kamp-paper'],
    ink: KAMP_PRIMITIVES['--kamp-ink'],
    muted: KAMP_PRIMITIVES['--kamp-muted'],
    line: KAMP_PRIMITIVES['--kamp-line'],
    lineControl: KAMP_PRIMITIVES['--kamp-line-control'],
    orange: KAMP_PRIMITIVES['--kamp-orange'],
    orangeTint: KAMP_PRIMITIVES['--kamp-orange-tint'],
    ok: KAMP_PRIMITIVES['--kamp-ok'],
    okTint: KAMP_PRIMITIVES['--kamp-ok-tint'],
    wait: KAMP_PRIMITIVES['--kamp-wait'],
    waitTint: KAMP_PRIMITIVES['--kamp-wait-tint'],
    stop: KAMP_PRIMITIVES['--kamp-stop'],
    stopTint: KAMP_PRIMITIVES['--kamp-stop-tint'],
    forest: KAMP_PRIMITIVES['--kamp-forest'],
    forestDeep: KAMP_PRIMITIVES['--kamp-forest-deep'],
    sand: KAMP_PRIMITIVES['--kamp-sand'],
} as const;

/**
 * Kamp Love brand defaults.
 *
 * These are the fallbacks used when the corresponding deployment environment
 * variable is unset. They exist so that an unconfigured build is Kamp Love
 * rather than the upstream Hi.Events default.
 */
export const KAMP_BRAND_NAME = 'Kamp Love';
export const KAMP_PRIVACY_URL = 'https://kamplove.org/privacy-policy';
export const KAMP_TERMS_URL = 'https://kamplove.org/terms-conditions';
export const KAMP_SITE_URL = 'https://kamplove.org';
