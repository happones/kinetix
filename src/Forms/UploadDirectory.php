<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms;

use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Http\Request;

/**
 * Where a user's uploads land: the field's directory (`uploads` by default),
 * namespaced per team when uploads are team-scoped (`kinetix.filesystem.teams`,
 * following `kinetix.teams`) and per user when
 * `kinetix.filesystem.scope_uploads_by_user` is on. The upload endpoint stores
 * there, and whatever later trusts a submitted path (the media library) checks
 * it against the same place.
 */
final class UploadDirectory
{
    /**
     * The upload directory for a user and, when given, a team. Both segments are
     * hashed: the folder names a stored file's owner without putting raw keys
     * in public URLs.
     */
    public static function for(?string $directory, int|string|null $userKey, int|string|null $teamKey = null): string
    {
        $directory = trim($directory ?? 'uploads', '/');

        if ($teamKey !== null) {
            $directory .= '/'.md5('team|'.$teamKey);
        }

        if (! config('kinetix.filesystem.scope_uploads_by_user', true) || $userKey === null) {
            return $directory;
        }

        return $directory.'/'.md5((string) $userKey);
    }

    /**
     * The current request's upload directory: its user and, when uploads are
     * team-scoped, its team (membership-checked).
     */
    public static function current(?string $directory, ?Request $request = null): string
    {
        $request ??= request();

        return self::for(
            $directory,
            ($request->user() ?? auth()->user())?->getAuthIdentifier(),
            KinetixTeams::keyFor('filesystem', $request),
        );
    }

    /**
     * Whether uploads are team-scoped but the request resolves no team: such a
     * request may not upload, nor adopt an upload (fail closed).
     */
    public static function teamMissing(?Request $request = null): bool
    {
        return KinetixTeams::enabledFor('filesystem') && KinetixTeams::keyFor('filesystem', $request ?? request()) === null;
    }

    /**
     * Whether a submitted path is a file inside the directory (no `..`).
     */
    public static function contains(string $directory, string $path): bool
    {
        return ! str_contains($path, '..') && str_starts_with($path, trim($directory, '/').'/');
    }
}
