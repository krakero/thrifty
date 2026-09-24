<?php

namespace App\Scanning;

/**
 * Short counter labels for the stats ribbon: 999, 1.2k, 12k, 123k, 1.2M.
 */
class CompactNumber
{
    public static function format(int $value): string
    {
        $magnitude = abs($value);

        foreach ([1_000_000_000 => 'B', 1_000_000 => 'M', 1_000 => 'k'] as $unit => $suffix) {
            if ($magnitude >= $unit) {
                $scaled = $value / $unit;
                $decimals = abs($scaled) < 10 ? 1 : 0;

                return rtrim(rtrim(number_format($scaled, $decimals, '.', ''), '0'), '.').$suffix;
            }
        }

        return (string) $value;
    }
}
