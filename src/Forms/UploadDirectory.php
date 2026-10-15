<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms;

/**
 * Where a user's uploads land: the field's directory (`uploads` by default),
 * namespaced per user when `kinetix.filesystem.scope_uploads_by_user` is on.
 * The upload endpoint stores there, and whatever later trusts a submitted
 * path (the media library) checks it against the same place.
 */
final class UploadDirectory
{
    public static function for(?string $directory, int|string|null $userKey): string
    {
        $directory = trim($directory ?? 'uploads', '/');

        if (! config('kinetix.filesystem.scope_uploads_by_user', true) || $userKey === null) {
            return $directory;
        }

        return $directory.'/'.md5((string) $userKey);
    }

    /**
     * Whether a submitted path is a file inside the directory (no `..`).
     */
    public static function contains(string $directory, string $path): bool
    {
        return ! str_contains($path, '..') && str_starts_with($path, trim($directory, '/').'/');
    }
}
