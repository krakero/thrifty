<?php

use App\Scanning\CompactNumber;

it('formats counters compactly', function (int $value, string $expected) {
    expect(CompactNumber::format($value))->toBe($expected);
})->with([
    [0, '0'],
    [999, '999'],
    [1000, '1k'],
    [1234, '1.2k'],
    [9999, '10k'],
    [18760, '19k'],
    [10000, '10k'],
    [20000, '20k'],
    [100000, '100k'],
    [1000000, '1M'],
    [-2500, '-2.5k'],
    [123456, '123k'],
    [2468000, '2.5M'],
    [3_000_000_000, '3B'],
]);
