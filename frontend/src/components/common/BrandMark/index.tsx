import classNames from "classnames";
import classes from "./BrandMark.module.scss";
import {appLogoDark, appLogoLight, appName} from "../../../utilites/branding";

interface BrandMarkProps {
    /** Which logo slot to prefer when one is configured. */
    variant?: 'light' | 'dark';
    className?: string;
    /**
     * Set when the mark sits beside a visible occurrence of the brand name, so the
     * name is not announced twice.
     */
    decorative?: boolean;
}

/**
 * Customer-facing brand mark.
 *
 * There is no Kamp Love logo asset in this repository. Rather than fall back to
 * the upstream Hi.Events mark, this renders a text wordmark in the Kamp editorial
 * face. Setting VITE_APP_LOGO_LIGHT / VITE_APP_LOGO_DARK swaps in a real asset
 * with no code change.
 */
export const BrandMark = ({variant = 'light', className, decorative = false}: BrandMarkProps) => {
    const name = appName();
    const logo = variant === 'dark' ? appLogoDark() : appLogoLight();

    if (logo) {
        return (
            <img
                src={logo}
                alt={decorative ? '' : name}
                aria-hidden={decorative || undefined}
                className={classNames(classes.logo, className)}
            />
        );
    }

    return (
        <span
            className={classNames(classes.wordmark, className)}
            aria-hidden={decorative || undefined}
        >
            {name}
        </span>
    );
};

export default BrandMark;
