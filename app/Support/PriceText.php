<?php

namespace App\Support;

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
     * A value summary with Markdown links reduced to their titles.
     */
    public static function plainSummary(string $summary): string
    {
        return preg_replace(self::MARKDOWN_LINK, '$1', $summary) ?? $summary;
    }
}
