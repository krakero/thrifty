<?php

namespace App\Support;

use App\Models\Item;

/**
 * Display formatting for integer cent amounts, matching the web app's `money()` and `formatRange()`.
 */
class Money
{
    public static function format(?int $cents, string $currency = 'USD'): string
    {
        if ($cents === null) {
            return '—';
        }

        $symbol = match (strtoupper($currency)) {
            'USD', 'CAD', 'AUD' => '$',
            'EUR' => '€',
            'GBP' => '£',
            default => strtoupper($currency).' ',
        };

        $decimals = $cents % 100 === 0 ? 0 : 2;

        return $symbol.number_format($cents / 100, $decimals);
    }

    /**
     * The conservative resale range, e.g. "$20–$35", or a single bound when only one is known.
     */
    public static function resaleRange(Item $item): string
    {
        $low = $item->estimated_low_cents;
        $high = $item->estimated_high_cents;

        if ($low === null && $high === null) {
            return '—';
        }

        if ($low === null || $high === null || $low === $high) {
            return self::format($low ?? $high, $item->currency);
        }

        return self::format($low, $item->currency).'–'.self::format($high, $item->currency);
    }
}
