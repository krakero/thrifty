<?php

namespace Thrifty\Camera\Facades;

use Illuminate\Support\Facades\Facade;

/**
 * @method static void snapshot(string $directory)
 * @method static string extractVideoFrames(string $videoPath, int $intervalSeconds, string $directory)
 * @method static void cancelVideoExtraction(string $runId)
 * @method static void setVideoFrameInterval(string $runId, int $seconds)
 * @method static array{active: bool, framesEmitted: int} videoRunStatus(string $runId)
 * @method static list<\Thrifty\Camera\Events\FrameCaptured|\Thrifty\Camera\Events\VideoFramesExtracted|\Thrifty\Camera\Events\CameraFailed> takeVideoEvents(string $runId)
 * @method static void forgetVideoRun(string $runId)
 * @method static void importImage(string $imagePath, string $directory)
 * @method static void shutter()
 * @method static string|null deviceTimezone()
 * @method static void chime()
 * @method static void shareFindCard(array{title: string, subtitle: string, imagePath: string, box?: array{xMin: int|float, yMin: int|float, xMax: int|float, yMax: int|float}|null, boxes?: list<array{xMin: int|float, yMin: int|float, xMax: int|float, yMax: int|float, selected?: bool}>, rows: list<array{label: string, value: string}>, summary: string} $card)
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
