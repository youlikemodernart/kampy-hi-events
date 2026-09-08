import {describe, expect, it} from 'vitest';
import {readFileSync} from 'node:fs';
import {fileURLToPath} from 'node:url';
import {dirname, join} from 'node:path';
import {getConfirmationHeadline} from './confirmationCopy';
import type {Event, Order} from '../types';

const here = dirname(fileURLToPath(import.meta.url));

const event = (overrides: Partial<Event> = {}): Pick<Event, 'title' | 'settings'> => ({
    title: 'Fall Kamp 2026',
    settings: undefined,
    ...overrides,
} as Pick<Event, 'title' | 'settings'>);

const withVenue = (venue_name?: string, is_online_event = false) =>
    event({
        settings: {
            is_online_event,
            location_details: venue_name === undefined ? undefined : {venue_name},
        },
    } as Partial<Event>);

const order = (status: string) => ({status} as Pick<Order, 'status'>);

describe('Rule C-1: confirmation headline selection', () => {
    it('names the event and the venue for a completed physical order', () => {
        const result = getConfirmationHeadline(
            order('COMPLETED'),
            withVenue('Grand Valley State University'),
        );

        expect(result).toEqual({
            kind: 'going_to_venue',
            eventTitle: 'Fall Kamp 2026',
            venueName: 'Grand Valley State University',
        });
    });

    it('falls back to the short form when no venue name is set', () => {
        expect(getConfirmationHeadline(order('COMPLETED'), withVenue(undefined)).kind)
            .toBe('going_to');
    });

    it('treats a blank or whitespace venue name as absent', () => {
        expect(getConfirmationHeadline(order('COMPLETED'), withVenue('')).kind).toBe('going_to');
        expect(getConfirmationHeadline(order('COMPLETED'), withVenue('   ')).kind).toBe('going_to');
    });

    it('trims a padded venue name rather than rendering the padding', () => {
        expect(getConfirmationHeadline(order('COMPLETED'), withVenue('  Camp Roger  ')).venueName)
            .toBe('Camp Roger');
    });

    it('uses the online phrasing for an online event, even when a venue is set', () => {
        // "You're going to X at Y" is wrong for an online Kamp.
        expect(getConfirmationHeadline(order('COMPLETED'), withVenue('Zoom', true)).kind)
            .toBe('online');
    });

    it('names the event in the awaiting-payment and cancelled states', () => {
        // A buyer with three registrations needs to know which one this is about.
        const awaiting = getConfirmationHeadline(order('AWAITING_OFFLINE_PAYMENT'), withVenue('GVSU'));
        expect(awaiting.kind).toBe('awaiting_payment');
        expect(awaiting.eventTitle).toBe('Fall Kamp 2026');

        const cancelled = getConfirmationHeadline(order('CANCELLED'), withVenue('GVSU'));
        expect(cancelled.kind).toBe('cancelled');
        expect(cancelled.eventTitle).toBe('Fall Kamp 2026');
    });

    it('shows no headline while the order is still in the step flow', () => {
        expect(getConfirmationHeadline(order('RESERVED'), withVenue('GVSU')).kind).toBe('none');
        expect(getConfirmationHeadline(order('ABANDONED'), withVenue('GVSU')).kind).toBe('none');
    });

    it('survives entirely absent settings', () => {
        expect(getConfirmationHeadline(order('COMPLETED'), event()).kind).toBe('going_to');
    });
});

describe('Rule C-1: how the headline is rendered', () => {
    const source = readFileSync(
        join(here, '../components/routes/product-widget/OrderSummaryAndProducts/index.tsx'),
        'utf8',
    );

    it('renders each state as one whole translatable message, not a concatenation', () => {
        // Translators must be able to reorder the event name and venue relative to
        // the surrounding words. A fragment plus a concatenated title cannot be.
        expect(source).toMatch(/<Trans>You're going to \{eventTitle\} at \{venueName\}<\/Trans>/);
        expect(source).toMatch(/<Trans>You're going to \{eventTitle\}<\/Trans>/);
        expect(source).toMatch(/<Trans>You're all set for \{eventTitle\}<\/Trans>/);

        // The old concatenated form must not come back.
        expect(source).not.toMatch(/t`You're going to \$\{event\.title\}!`/);
    });

    it('makes the confirmation sentence the page h1 and demotes section labels', () => {
        expect(source).toMatch(/<h1 className=\{classes\.welcomeMessage\}>/);
        expect(source).not.toMatch(/<h1 className=\{classes\.heading\}>/);
    });

    it('hides the decorative status emoji from assistive technology', () => {
        expect(source).toMatch(/className=\{classes\.confettiIcon\} aria-hidden="true"/);
    });
});
