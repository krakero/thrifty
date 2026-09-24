<?php

namespace App\Support;

use App\Models\Item;

/**
 * Display helpers shared by the find card and detail screens.
 */
class PriceText
{
    /** Web results linked from a value summary, as Markdown links. */
    public const MARKDOWN_LINK = '/\[([^\]]+)]\((https?:\/\/[^)]+)\)/';

    private const WORD_JOINER = "\u{2060}";

    private const NO_BREAK_SPACE = "\u{00A0}";

    /**
     * Glue a price, range or short label into one unbreakable run, so "$116.36–$260.68" or "Seen 2×" never wraps at
     * the dash or space (SwiftUI would otherwise break after the en dash).
     */
    public static function keepTogether(string $text): string
    {
        return str_replace(
            ['–', ' '],
            [self::WORD_JOINER.'–'.self::WORD_JOINER, self::NO_BREAK_SPACE],
            $text,
        );
    }

    /**
     * How VoiceOver should read a find's resale estimate: "resale $20 to $35", or "resale value pending" with no estimate.
     */
    public static function spokenResale(Item $item): string
    {
        $range = Money::resaleRange($item);

        if ($item->estimated_low_cents === null && $item->estimated_high_cents === null) {
            return 'resale '.mb_strtolower($range);
        }

        return 'resale '.str_replace('–', ' to ', $range);
    }

    /**
     * A value summary with Markdown links reduced to their titles.
     */
    public static function plainSummary(string $summary): string
    {
        return preg_replace(self::MARKDOWN_LINK, '$1', $summary) ?? $summary;
    }
}
