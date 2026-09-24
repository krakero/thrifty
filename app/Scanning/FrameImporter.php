<?php

namespace App\Scanning;

use App\Agent\Exceptions\AnalysisFailed;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Moves captured and uploaded images into the `frames/` directory of the `local` disk.
 */
class FrameImporter
{
    public const Directory = 'frames';

    public const MaxLongestSide = 1280;

    public const JpegQuality = 82;

    /** @var list<string> */
    private const PassThroughExtensions = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    /**
     * Absolute path of the frames directory, as handed to the camera plugin.
     */
    public static function directory(): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::Directory);

        return $disk->path(self::Directory);
    }

    /**
     * Convert an absolute path on the `local` disk back to a disk-relative one.
     */
    public static function relativePath(string $absolutePath): string
    {
        $root = rtrim(Storage::disk('local')->path(''), '/').'/';

        return Str::startsWith($absolutePath, $root) ? Str::after($absolutePath, $root) : ltrim($absolutePath, '/');
    }

    /**
     * Copy an uploaded photo into `frames/` as a JPEG no larger than the live frames, as the web app
     * re-encodes uploads before analysis. Formats GD can't decode are copied as-is when the model accepts them.
     *
     * @return string The new frame path, relative to the `local` disk.
     *
     * @throws AnalysisFailed
     */
    public function importImage(string $absolutePath): string
    {
        $bytes = is_readable($absolutePath) ? file_get_contents($absolutePath) : false;

        if ($bytes === false || $bytes === '') {
            throw new AnalysisFailed('The selected image could not be read.');
        }

        $jpeg = $this->downscaledJpeg($bytes);

        if ($jpeg !== null) {
            return $this->store($jpeg, 'jpg');
        }

        $extension = strtolower(pathinfo($absolutePath, PATHINFO_EXTENSION));

        if (! in_array($extension, self::PassThroughExtensions, true)) {
            throw new AnalysisFailed('This image format is not supported. Choose a JPEG or PNG photo.');
        }

        return $this->store($bytes, $extension === 'jpeg' ? 'jpg' : $extension);
    }

    public function delete(string $relativePath): void
    {
        Storage::disk('local')->delete($relativePath);
    }

    private function store(string $bytes, string $extension): string
    {
        $path = self::Directory.'/'.Str::ulid().'.'.$extension;
        Storage::disk('local')->put($path, $bytes);

        return $path;
    }

    private function downscaledJpeg(string $bytes): ?string
    {
        if (! function_exists('imagecreatefromstring') || ! function_exists('imagejpeg')) {
            return null;
        }

        $image = @imagecreatefromstring($bytes);

        if ($image === false) {
            return null;
        }

        $width = imagesx($image);
        $height = imagesy($image);
        $scale = min(1, self::MaxLongestSide / max($width, $height, 1));

        if ($scale < 1) {
            $resized = imagescale($image, max(1, (int) round($width * $scale)), max(1, (int) round($height * $scale)));
            $image = $resized === false ? $image : $resized;
        }

        ob_start();
        $encoded = imagejpeg($image, null, self::JpegQuality);
        $jpeg = (string) ob_get_clean();

        return $encoded && $jpeg !== '' ? $jpeg : null;
    }
}
