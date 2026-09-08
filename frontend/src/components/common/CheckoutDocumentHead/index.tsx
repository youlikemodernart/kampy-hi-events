import {Helmet} from "react-helmet-async";
import {appName} from "../../../utilites/branding.ts";

interface CheckoutDocumentHeadProps {
    /** Already-localised page title fragment, e.g. "Your details". */
    title: string;
    /** Event title, when the surface has event context. */
    eventTitle?: string | null;
}

/**
 * Document head for transactional surfaces.
 *
 * Checkout, order lookup, the single-ticket page and the print views previously
 * rendered no Helmet at all, so their document title fell through to the global
 * default in App.tsx. Bookmarks, tab titles and browser history for a paid order
 * therefore carried the application name rather than the event.
 *
 * These surfaces are always noindex: they are per-order URLs.
 */
export const CheckoutDocumentHead = ({title, eventTitle}: CheckoutDocumentHeadProps) => {
    const documentTitle = eventTitle
        ? `${title} - ${eventTitle}`
        : `${title} - ${appName()}`;

    return (
        <Helmet>
            <title>{documentTitle}</title>
            <meta name="robots" content="noindex, nofollow"/>
        </Helmet>
    );
};

export default CheckoutDocumentHead;
