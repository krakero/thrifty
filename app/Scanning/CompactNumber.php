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

                $formatted = number_format($scaled, $decimals, '.', '');

                return (str_ends_with($formatted, '.0') ? substr($formatted, 0, -2) : $formatted).$suffix;
            }
        }

        return (string) $value;
    }
}
