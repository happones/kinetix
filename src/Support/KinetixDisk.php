<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Helpers for resolving the Kinetix storage disk and bridging cloud disks
 * (e.g. S3) to a local path when a feature needs to read/write a real file
 * (CSV/XLSX parsing and writing only work against the local filesystem).
 */
class KinetixDisk
{
    /**
     * The configured default disk (uploads, exports, imports, asset URLs).
     */
    public static function name(): string
    {
        return (string) config('kinetix.filesystem.disk', 'public');
    }

    /**
     * Return a local, readable path for a file on the given disk. Local-driver
     * disks expose the real path directly; cloud disks are streamed to a temp
     * file. Always pair with {@see discardTemp()}.
     *
     * @return array{0: string, 1: bool} [localPath, isTemporary]
     */
    public static function localReadablePath(string $disk, string $path): array
    {
        $storage = Storage::disk($disk);

        try {
            $absolute = $storage->path($path);

            if (is_file($absolute)) {
                return [$absolute, false];
            }
        } catch (Throwable) {
            // Disk has no usable local path (cloud driver) — fall through.
        }

        $temp = (string) tempnam(sys_get_temp_dir(), 'kinetix_');
        file_put_contents($temp, $storage->get($path));

        return [$temp, true];
    }

    /**
     * Remove a temp file created by {@see localReadablePath()}.
     */
    public static function discardTemp(string $localPath, bool $isTemporary): void
    {
        if ($isTemporary && is_file($localPath)) {
            @unlink($localPath);
        }
    }
}
