<?php

namespace App\Agent;

/**
 * Crops an item's bounding box (with the web app's padding) out of a frame as a JPEG thumbnail.
 */
class Thumbnailer
{
    public const Quality = 82;

    public static function isAvailable(): bool
    {
        return function_exists('imagecreatefromstring') && function_exists('imagejpeg');
    }

    /**
     * Crop several boxes out of one frame, decoding it once.
     *
     * @param  list<array{xMin: int, yMin: int, xMax: int, yMax: int}>  $boxes  Normalized 0-1000 coordinates.
     * @return list<?string> JPEG bytes per box, or null where a crop can't be made.
     */
    public function cropAll(string $frameBytes, array $boxes): array
    {
        $frame = self::isAvailable() && $boxes !== [] ? self::decode($frameBytes) : false;

        return array_map(fn (array $box): ?string => $frame === false ? null : self::cropImage($frame, $box), $boxes);
    }

    /**
     * @param  array{xMin: int, yMin: int, xMax: int, yMax: int}  $box  Normalized 0-1000 coordinates.
     * @return string|null JPEG bytes, or null when the crop can't be made.
     */
    public function crop(string $frameBytes, array $box): ?string
    {
        return $this->cropAll($frameBytes, [$box])[0];
    }

    /**
     * Decode an image, treating unreadable data as "no crop" rather than a PHP warning.
     */
    private static function decode(string $bytes): \GdImage|false
    {
        set_error_handler(fn (): bool => true);

        try {
            return getimagesizefromstring($bytes) !== false ? imagecreatefromstring($bytes) : false;
        } finally {
            restore_error_handler();
        }
    }

    /**
     * @param  array{xMin: int, yMin: int, xMax: int, yMax: int}  $box
     */
    private static function cropImage(\GdImage $frame, array $box): ?string
    {
        if ($box['xMax'] <= $box['xMin'] || $box['yMax'] <= $box['yMin']) {
            return null;
        }

        $crop = self::paddedCrop($box, imagesx($frame), imagesy($frame));
        $cropped = $crop['width'] >= 1 && $crop['height'] >= 1 ? imagecrop($frame, $crop) : false;

        if ($cropped === false) {
            return null;
        }

        ob_start();
        $encoded = imagejpeg($cropped, null, self::Quality);
        $bytes = ob_get_clean();

        return $encoded && is_string($bytes) && $bytes !== '' ? $bytes : null;
    }

    /**
     * Port of the web app's `paddedCrop()`: 18% padding per side (at least 2% of the frame), clamped to the frame.
     *
     * @param  array{xMin: int, yMin: int, xMax: int, yMax: int}  $box
     * @return array{x: int, y: int, width: int, height: int}
     */
    public static function paddedCrop(array $box, int $imageWidth, int $imageHeight): array
    {
        $x = ($box['xMin'] / 1000) * $imageWidth;
        $y = ($box['yMin'] / 1000) * $imageHeight;
        $width = (($box['xMax'] - $box['xMin']) / 1000) * $imageWidth;
        $height = (($box['yMax'] - $box['yMin']) / 1000) * $imageHeight;
        $paddingX = max($width * 0.18, $imageWidth * 0.02);
        $paddingY = max($height * 0.18, $imageHeight * 0.02);
        $cropX = max(0, $x - $paddingX);
        $cropY = max(0, $y - $paddingY);

        $left = (int) round($cropX);
        $top = (int) round($cropY);

        return [
            'x' => $left,
            'y' => $top,
            'width' => (int) min($imageWidth - $left, round($width + $paddingX * 2)),
            'height' => (int) min($imageHeight - $top, round($height + $paddingY * 2)),
        ];
    }
}
