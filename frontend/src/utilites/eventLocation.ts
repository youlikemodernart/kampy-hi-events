import {Event} from "../types.ts";

export const getPublicVenueAttribute = (
    event: Pick<Event, 'attributes'>,
): string | undefined => {
    const venue = event.attributes?.find((attribute) =>
        attribute.is_public
        && attribute.name.trim().toLowerCase() === 'venue'
        && attribute.value.trim().length > 0
    );

    return venue?.value.trim();
};
