<?php

namespace Thrifty\Camera\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void snapshot(string $directory)
 * @method static void extractVideoFrames(string $videoPath, int $intervalSeconds, string $directory)
 * @method static void importImage(string $imagePath, string $directory)
 * @method static void chime()
 * @method static void shareFindCard(array{title: string, subtitle: string, imagePath: string, box: array{xMin: int|float, yMin: int|float, xMax: int|float, yMax: int|float}|null, rows: list<array{label: string, value: string}>, summary: string} $card)
 *
 * @see \Thrifty\Camera\ThriftyCamera
 */
class ThriftyCamera extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return \Thrifty\Camera\ThriftyCamera::class;
    }
}
