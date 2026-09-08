import {MantineProvider, MantineThemeOverride, CSSVariablesResolver, MantineColorsTuple, ButtonProps, CheckboxProps, MantineTheme} from "@mantine/core";
import {PropsWithChildren, useMemo} from "react";
import {getContrastColor, hexToRgb} from "../../../utilites/themeUtils";
import {kamp} from "../../../styles/kampPrimitives";

interface CheckoutThemeProviderProps {
    accentColor: string;
    mode: 'light' | 'dark';
}

/**
 * Fixed color palettes for checkout - these ensure good contrast and readability.
 * Users can only customize accent color, not the base palette.
 *
 * Every light-mode value derives from the canonical Kamp Love primitive set in
 * `styles/kampPrimitives.ts`, which is held in agreement with the `--kamp-*`
 * declarations in `styles/global.scss` by `kampPrimitives.test.ts`. A change to
 * a Kamp primitive therefore reaches checkout.
 *
 * `surfaceMuted` is a distinct fill role. It exists because `border` (a hairline
 * colour) was previously reused as a surface fill by the progress stepper, which
 * produced a 2.21:1 text contrast. Fills must not borrow the border role.
 */
const LIGHT_PALETTE = {
    surface: kamp.paper,
    surfaceMuted: kamp.sand,
    background: kamp.cream,
    textPrimary: kamp.ink,
    textSecondary: kamp.muted,
    textTertiary: kamp.muted,
    border: kamp.lineControl,
};

/**
 * Checkout is pinned to light mode (see `Checkout/index.tsx`), so this palette is
 * currently unreachable. It is retained as the structural counterpart rather than
 * deleted, and is deliberately NOT derived from Kamp primitives: the Kamp Love
 * source declares no dark context. See the dark-context question in the Phase 1
 * report before making this reachable.
 */
const DARK_PALETTE = {
    surface: '#1f1f1f',
    surfaceMuted: '#2a2a2a',
    background: '#121212',
    textPrimary: '#ffffff',
    textSecondary: '#a3a3a3',
    textTertiary: '#737373',
    border: '#333333',
};

/**
 * Creates a color palette that preserves the user's exact accent color.
 */
function createColorPalette(accentColor: string): MantineColorsTuple {
    // Unparseable accent falls back to the Kamp forest ramp rather than a neutral
    // grey ramp, so a bad config still reads as Kamp Love. `kamp.forest` is a
    // literal hex, so the second call always resolves.
    const rgb = hexToRgb(accentColor) ?? hexToRgb(kamp.forest)!;

    const {r, g, b} = rgb;

    const lighten = (factor: number) => {
        const lr = Math.round(r + (255 - r) * factor);
        const lg = Math.round(g + (255 - g) * factor);
        const lb = Math.round(b + (255 - b) * factor);
        return `rgb(${lr}, ${lg}, ${lb})`;
    };

    const darken = (factor: number) => {
        const dr = Math.round(r * (1 - factor));
        const dg = Math.round(g * (1 - factor));
        const db = Math.round(b * (1 - factor));
        return `rgb(${dr}, ${dg}, ${db})`;
    };

    return [
        lighten(0.9),
        lighten(0.8),
        lighten(0.6),
        lighten(0.4),
        lighten(0.2),
        accentColor,
        accentColor,
        accentColor,
        darken(0.15),
        darken(0.3),
    ];
}

/**
 * Creates a Mantine theme with the user's accent color.
 */
function createCheckoutTheme(accentColor: string, mode: 'light' | 'dark'): MantineThemeOverride {
    const primaryColors = createColorPalette(accentColor);
    const contrastColor = getContrastColor(accentColor);

    return {
        primaryColor: 'primary',
        colors: {
            primary: primaryColors,
        },
        primaryShade: mode === 'dark' ? 6 : 7,
        fontFamily: kamp.fontSerif,
        headings: {
            fontFamily: kamp.fontSerif,
            fontWeight: '400',
        },
        defaultRadius: 'md',
        components: {
            Button: {
                defaultProps: {
                    color: 'primary',
                },
                vars: (_theme: MantineTheme, props: ButtonProps) => {
                    if (props.variant === 'filled' || props.variant === undefined) {
                        return {
                            root: {
                                '--button-color': contrastColor,
                                '--button-radius': '999px',
                            },
                        };
                    }
                    return { root: {} };
                },
            },
            Checkbox: {
                defaultProps: {
                    color: 'primary',
                },
                vars: (_theme: MantineTheme, _props: CheckboxProps) => ({
                    root: {
                        '--checkbox-icon-color': contrastColor,
                    },
                }),
            },
            Switch: {
                defaultProps: {
                    color: 'primary',
                },
            },
            SegmentedControl: {
                defaultProps: {
                    color: 'primary',
                },
            },
            Badge: {
                defaultProps: {
                    color: 'primary',
                },
            },
        },
    };
}

/**
 * Creates CSS variables for checkout theming.
 * Surface, text, and border colors are FIXED based on light/dark mode.
 * Only accent color is customizable.
 */
function createCSSVariablesResolver(accentColor: string, mode: 'light' | 'dark'): CSSVariablesResolver {
    return () => {
        const palette = mode === 'light' ? LIGHT_PALETTE : DARK_PALETTE;
        const accentContrast = getContrastColor(accentColor);
        // Unparseable accent falls back to the Kamp forest channel values rather
        // than to a neutral ink tint.
        const rgb = hexToRgb(accentColor) ?? hexToRgb(kamp.forest)!;

        const accentSoft = `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${mode === 'light' ? 0.1 : 0.2})`;
        const accentMuted = `rgba(${rgb.r}, ${rgb.g}, ${rgb.b}, ${mode === 'light' ? 0.6 : 0.7})`;

        return {
            variables: {
                // Accent colors (customizable)
                '--checkout-accent': accentColor,
                '--checkout-accent-contrast': accentContrast,
                '--checkout-accent-soft': accentSoft,
                '--checkout-accent-muted': accentMuted,

                // Fixed palette colors (not customizable - ensures readability)
                '--checkout-background': palette.background,
                '--checkout-surface': palette.surface,
                '--checkout-surface-muted': palette.surfaceMuted,
                '--checkout-text-primary': palette.textPrimary,
                '--checkout-text-secondary': palette.textSecondary,
                '--checkout-text-tertiary': palette.textTertiary,
                '--checkout-border': palette.border,
                '--checkout-brand-orange': kamp.orange,
                '--checkout-brand-orange-soft': kamp.orangeTint,
                '--checkout-font-serif': kamp.fontSerif,
                '--checkout-font-utility': kamp.fontUtility,

                // State roles, so transactional surfaces stop inventing local
                // success/pending/error colours.
                '--checkout-ok': kamp.ok,
                '--checkout-ok-soft': kamp.okTint,
                '--checkout-wait': kamp.wait,
                '--checkout-wait-soft': kamp.waitTint,
                '--checkout-stop': kamp.stop,
                '--checkout-stop-soft': kamp.stopTint,

                // Generic role aliases. These are the names the shared components
                // (Card, HomepageInfoMessage, ProgressStepper) already read on the
                // event page via EventHomepage.module.scss. Declaring them here makes
                // those components resolve correctly in BOTH contexts from one set of
                // declarations, instead of silently falling back to foreign literals.
                '--primary-color': accentColor,
                '--primary-text-color': palette.textPrimary,
                '--secondary-color': palette.textSecondary,
                '--secondary-text-color': palette.textSecondary,
                '--content-bg-color': palette.surface,
                '--bg-color': palette.background,
                '--border-color': palette.border,
                '--accent-contrast': accentContrast,
                '--accent-soft': accentSoft,
                '--accent-muted': accentMuted,

                // Override global --hi-text (set to accent in global.scss) and
                // Mantine's default text color to use fixed palette instead
                '--hi-text': palette.textPrimary,
                '--mantine-color-text': palette.textPrimary,
            },
            light: {},
            dark: {},
        };
    };
}

/**
 * CheckoutThemeProvider wraps checkout with themed Mantine components.
 *
 * Design philosophy:
 * - Checkout uses FIXED light/dark palettes for surfaces, text, and borders
 * - Only the accent color (buttons, links, highlights) is customizable
 * - This ensures readability and accessibility regardless of user choices
 */
export const CheckoutThemeProvider = ({
    accentColor,
    mode,
    children,
}: PropsWithChildren<CheckoutThemeProviderProps>) => {
    const theme = useMemo(
        () => createCheckoutTheme(accentColor, mode),
        [accentColor, mode]
    );

    const cssVariablesResolver = useMemo(
        () => createCSSVariablesResolver(accentColor, mode),
        [accentColor, mode]
    );

    return (
        <MantineProvider
            theme={theme}
            cssVariablesResolver={cssVariablesResolver}
            forceColorScheme={mode}
        >
            {children}
        </MantineProvider>
    );
};

export default CheckoutThemeProvider;
