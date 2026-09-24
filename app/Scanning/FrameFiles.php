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

    public const ImportPrefix = 'import-';

    /** Where nativephp/mobile-camera's gallery picker copies picked files, inside the app's temporary directory. */
    public const PickerDirectory = 'Gallery';

    public const PickerFilePattern = 'gallery_selected_*';

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

    /**
     * A per-pick import directory under `frames/`, created up front, as an absolute path for the plugin.
     */
    public static function importDirectory(string $token): string
    {
        $directory = self::FramesDirectory.'/'.self::ImportPrefix.$token;
        Storage::disk('local')->makeDirectory($directory);

        return Storage::disk('local')->path($directory);
    }

    /**
     * The import token of a frame written into an import directory, or null for any other frame.
     */
    public static function importToken(string $framePath): ?string
    {
        $directory = basename(dirname($framePath));

        return str_starts_with($directory, self::ImportPrefix) ? substr($directory, strlen(self::ImportPrefix)) : null;
    }

    /**
     * Move an imported frame to the top of `frames/`, like every other frame, and drop its import directory.
     *
     * @return string The new frame path, relative to the `local` disk.
     */
    public function adoptImportedFrame(string $framePath): string
    {
        $disk = Storage::disk('local');
        $target = self::FramesDirectory.'/'.basename($framePath);
        $disk->move($framePath, $target);
        $disk->deleteDirectory(dirname($framePath));

        return $target;
    }

    public function deleteImportDirectory(string $token): void
    {
        Storage::disk('local')->deleteDirectory(self::FramesDirectory.'/'.self::ImportPrefix.$token);
    }

    /**
     * Remove stage previews and import directories left behind by an earlier run of the app (e.g. after a kill).
     *
     * @param  list<string>  $keepPreviews  Preview paths still in use.
     * @param  list<string>  $keepImports  Import tokens still in progress.
     */
    public function sweep(array $keepPreviews, array $keepImports): void
    {
        $disk = Storage::disk('local');

        foreach ($disk->files(self::PreviewsDirectory) as $preview) {
            if (! in_array($preview, $keepPreviews, true)) {
                $disk->delete($preview);
            }
        }

        foreach ($disk->directories(self::FramesDirectory) as $directory) {
            $token = self::importToken($directory.'/x');

            if ($token !== null && ! in_array($token, $keepImports, true)) {
                $disk->deleteDirectory($directory);
            }
        }
    }

    /**
     * Remove gallery copies left in the picker's temporary directory (e.g. a video picked before the app was
     * killed). Recent files and the ones still in use are kept.
     *
     * @param  list<string>  $keep  Absolute paths still in use.
     */
    public function sweepPickedMedia(array $keep, ?string $directory = null, int $minimumAgeSeconds = 60): void
    {
        $directory ??= rtrim(sys_get_temp_dir(), '/').'/'.self::PickerDirectory;

        foreach (glob($directory.'/'.self::PickerFilePattern) ?: [] as $file) {
            if (is_file($file) && ! in_array($file, $keep, true) && time() - self::pickedAt($file) >= $minimumAgeSeconds) {
                @unlink($file);
            }
        }
    }

    /**
     * When a gallery copy was picked (unix seconds). The copy keeps the source asset's modification time, so this
     * reads the millisecond timestamp the picker writes into the name (`gallery_selected_<ms>_<index>`), falling
     * back to the inode change time, which the copy sets.
     */
    public static function pickedAt(string $file): int
    {
        if (preg_match('/^gallery_selected_(\d{10,})_/', basename($file), $matches) === 1) {
            return intdiv((int) $matches[1], 1000);
        }

        return (int) filectime($file);
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
