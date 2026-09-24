<?php

use App\Agent\Normalize;

describe('normalizeFingerprint', function () {
    it('creates a stable identity without punctuation or irregular whitespace', function () {
        expect(Normalize::normalizeFingerprint('  Sony  Walkman®  WM-FX195! '))->toBe('sony walkman wm fx195');
    });

    it('caps fingerprints before they reach the database', function () {
        expect(Normalize::normalizeFingerprint(str_repeat('A', 250)))->toHaveLength(180);
    });

    it('matches semantically stable identities despite descriptive wording changes', function () {
        expect(Normalize::fingerprintSimilarity(
            'unbranded black metal glass console table',
            'unbranded black glass top console table',
        ))->toBeGreaterThan(0.72);

        expect(Normalize::fingerprintSimilarity(
            'unbranded rolled area rug',
            'unbranded rolled beige patterned area rug',
        ))->toBeGreaterThan(0.72);
    });

    it('does not merge distinct objects that only share a generic category', function () {
        expect(Normalize::fingerprintSimilarity('large round wall mirror', 'pink oval wall mirror'))->toBe(0.0);
        expect(Normalize::fingerprintSimilarity('rolled beige area rug', 'gray black abstract area rug'))->toBe(0.0);
    });

    it('retrieves likely duplicates from their names and descriptions despite different fingerprints', function () {
        $jonJosef = implode(' ', [
            'jon josef pointed toe flats',
            'Jon Josef pointed-toe flats',
            'Mint-green pointed-toe slip-on flats with visible Jon Josef branding and Made in Spain marking',
        ]);
        $mintPumps = implode(' ', [
            'mint green pointed toe pumps',
            'Mint green pointed-toe pumps',
            'Pair of mint green pointed-toe pumps with sculpted high heels and spring shoe trees',
        ]);

        expect(Normalize::fingerprintSimilarity($jonJosef, $mintPumps))->toBeGreaterThan(0.0);
    });

    it('treats aliases as the same token', function () {
        expect(Normalize::fingerprintSimilarity('decorative glass tabletop vase', 'decor glass top vase'))->toBe(1.0);
    });
});
