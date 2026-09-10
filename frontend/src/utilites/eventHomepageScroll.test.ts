import {describe, expect, it} from 'vitest';
import {shouldShowTicketScrollButton} from './eventHomepageScroll';

describe('ticket scroll button visibility', () => {
    const viewportHeight = 714;

    it.each([
        ['far below', 900, true],
        ['at the reveal boundary', 810, false],
        ['just beyond the reveal boundary', 810.01, true],
        ['just below the viewport', 734, false],
        ['visible', 500, false],
        ['above the viewport', -100, false],
        ['rubber-band overscroll', -500, false],
    ])('%s', (_case, ticketSectionTop, expected) => {
        expect(shouldShowTicketScrollButton(ticketSectionTop, viewportHeight)).toBe(expected);
    });
});
