<?php

namespace App\Scanning;

use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * Frame and stage-preview files on the `local` disk, plus cleanup of picked gallery originals.
 */
class FrameFiles
{
    public const FramesDirectory = 'frames';

    public const PreviewsDirectory = 'previews';

    /**
     * Absolute path of the frames directory, as handed to the camera plugin.
     */
    public static function framesDirectory(): string
    {
        $disk = Storage::disk('local');
        $disk->makeDirectory(self::FramesDirectory);

        return $disk->path(self::FramesDirectory);
    }

    /**
     * Convert an absolute path on the `local` disk back to a disk-relative one.
     */
    public static function relativePath(string $absolutePath): string
    {
        $root = rtrim(Storage::disk('local')->path(''), '/').'/';

        return Str::startsWith($absolutePath, $root) ? Str::after($absolutePath, $root) : ltrim($absolutePath, '/');
    }

    public static function absolutePath(string $relativePath): string
    {
        return Storage::disk('local')->path($relativePath);
    }

    /**
     * Copy a frame for the stage preview. The analyzer may delete the frame itself (no finds, failure),
     * but the web app keeps showing the photo, so the preview gets its own file.
     *
     * @return string|null The preview path, relative to the `local` disk.
     */
    public function copyToPreview(string $framePath): ?string
    {
        $disk = Storage::disk('local');
        $previewPath = self::PreviewsDirectory.'/'.Str::ulid().'.jpg';

        return $disk->exists($framePath) && $disk->copy($framePath, $previewPath) ? $previewPath : null;
    }

    public function delete(?string $relativePath): void
    {
        if ($relativePath !== null && $relativePath !== '') {
            Storage::disk('local')->delete($relativePath);
        }
    }

    /**
     * Remove a picked gallery file from the app's temporary directory once it's no longer needed.
     */
    public function deletePicked(?string $absolutePath): void
    {
        if ($absolutePath !== null && is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    }
}
