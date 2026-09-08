import {Event, Order} from "../types.ts";

/**
 * Which confirmation headline a given order/event pair should show.
 *
 * The selection is a pure function so it can be tested without a DOM or an i18n
 * runtime. The component owns the wording; this owns the choice.
 *
 * Rule C-1 from the accepted white-label specification:
 *   COMPLETED + physical + venue_name  -> "You're going to {event} at {venue}"
 *   COMPLETED + physical, no venue     -> "You're going to {event}"
 *   COMPLETED + online                 -> "You're all set for {event}"
 *   AWAITING_OFFLINE_PAYMENT           -> "Your spot at {event} is being held"
 *   CANCELLED                          -> "Your order for {event} has been cancelled"
 *   RESERVED / ABANDONED               -> no headline; the step flow owns the screen
 */
export type ConfirmationHeadlineKind =
    | 'going_to_venue'
    | 'going_to'
    | 'online'
    | 'awaiting_payment'
    | 'cancelled'
    | 'none';

export interface ConfirmationHeadline {
    kind: ConfirmationHeadlineKind;
    eventTitle: string;
    /** Only set for 'going_to_venue'. */
    venueName?: string;
}

/**
 * Venue name only. A formatted street address is deliberately NOT used as a
 * fallback: "You're going to Fall Kamp 2026 at 1 Campus Drive, Allendale, MI"
 * reads like a shipping label.
 */
const resolveVenueName = (event: Pick<Event, 'settings'>): string | undefined => {
    const venue = event.settings?.location_details?.venue_name;
    const trimmed = typeof venue === 'string' ? venue.trim() : '';
    return trimmed.length > 0 ? trimmed : undefined;
};

export const getConfirmationHeadline = (
    order: Pick<Order, 'status'>,
    event: Pick<Event, 'title' | 'settings'>,
): ConfirmationHeadline => {
    const eventTitle = event.title;

    switch (order.status) {
        case 'COMPLETED': {
            if (event.settings?.is_online_event) {
                return {kind: 'online', eventTitle};
            }

            const venueName = resolveVenueName(event);
            return venueName
                ? {kind: 'going_to_venue', eventTitle, venueName}
                : {kind: 'going_to', eventTitle};
        }

        case 'AWAITING_OFFLINE_PAYMENT':
            return {kind: 'awaiting_payment', eventTitle};

        case 'CANCELLED':
            return {kind: 'cancelled', eventTitle};

        default:
            return {kind: 'none', eventTitle};
    }
};
