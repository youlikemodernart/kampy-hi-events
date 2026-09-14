import {describe, expect, it} from 'vitest';
import {getPublicVenueAttribute} from './eventLocation.ts';

const event = (attributes?: {name: string; value: string; is_public: boolean}[]) => ({attributes});

describe('getPublicVenueAttribute', () => {
    it('returns a trimmed public Venue value', () => {
        expect(getPublicVenueAttribute(event([
            {name: 'Venue', value: ' 4694 35th St, Zeeland, Michigan ', is_public: true},
        ]))).toBe('4694 35th St, Zeeland, Michigan');
    });

    it('matches the Venue name without case sensitivity', () => {
        expect(getPublicVenueAttribute(event([
            {name: 'venue', value: 'Camp Roger', is_public: true},
        ]))).toBe('Camp Roger');
    });

    it('ignores private, blank, and unrelated attributes', () => {
        expect(getPublicVenueAttribute(event([
            {name: 'Venue', value: 'Private place', is_public: false},
            {name: 'Venue', value: '   ', is_public: true},
            {name: 'Campus', value: 'GVSU', is_public: true},
        ]))).toBeUndefined();
    });
});
