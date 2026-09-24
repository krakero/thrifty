<?php

namespace App\Agent;

/**
 * Fingerprint normalization and fuzzy identity matching used to dedupe finds across frames.
 */
class Normalize
{
    private const IgnoredTokens = ['a', 'an', 'and', 'for', 'generic', 'of', 'ornate', 'the', 'unbranded', 'unknown', 'with'];

    private const TokenAliases = [
        'decoration' => 'decor',
        'decorative' => 'decor',
        'tabletop' => 'top',
    ];

    /**
     * A stable lowercase identity without punctuation, capped at 180 characters.
     */
    public static function normalizeFingerprint(string $value): string
    {
        $normalized = preg_replace('/[^a-z0-9]+/', ' ', mb_strtolower($value));
        $normalized = preg_replace('/\s+/', ' ', trim((string) $normalized));

        return substr((string) $normalized, 0, 180);
    }

    /**
     * Blend of token containment and Jaccard overlap; 0 unless at least three meaningful tokens are shared.
     */
    public static function fingerprintSimilarity(string $left, string $right): float
    {
        $leftTokens = self::fingerprintTokens($left);
        $rightTokens = self::fingerprintTokens($right);

        if ($leftTokens === [] || $rightTokens === []) {
            return 0.0;
        }

        $shared = count(array_intersect_key($leftTokens, $rightTokens));

        if ($shared < 3) {
            return 0.0;
        }

        $containment = $shared / min(count($leftTokens), count($rightTokens));
        $jaccard = $shared / count($leftTokens + $rightTokens);

        return $containment * 0.65 + $jaccard * 0.35;
    }

    /**
     * @return array<string, true>
     */
    private static function fingerprintTokens(string $value): array
    {
        $tokens = [];

        foreach (explode(' ', self::normalizeFingerprint($value)) as $token) {
            $token = self::TokenAliases[$token] ?? $token;

            if ($token !== '' && ! in_array($token, self::IgnoredTokens, true)) {
                $tokens[$token] = true;
            }
        }

        return $tokens;
    }
}
