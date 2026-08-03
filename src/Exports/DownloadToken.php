<?php

declare(strict_types=1);

namespace Happones\Kinetix\Exports;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Crypt;
use Throwable;

/**
 * The signed token behind an export / report / GDPR download link.
 *
 * Encryption alone only proves Kinetix minted the payload — not that whoever
 * presents it is entitled to the file. So the token also records the recipient
 * and an expiry, because these files are the most sensitive things the package
 * produces: a full GDPR personal-data dump, or an export of every row a user
 * could see. Without binding, the link works forever for anyone who obtains it
 * from a shared browser, a proxy log or a Referer header.
 *
 * @phpstan-type Payload array{disk: string, path: string, name: string, user: int|string|null, expires: int|null}
 */
class DownloadToken
{
    /**
     * Mint a token for one recipient. A null recipient produces an unbound token
     * (any authenticated user may download it) — only for artifacts that aren't
     * tied to a user.
     */
    public static function mint(
        string $disk,
        string $path,
        string $name,
        int|string|null $userId = null,
    ): string {
        $ttl = config('kinetix.exports.download_ttl', 1440);

        return Crypt::encrypt([
            'disk'    => $disk,
            'path'    => $path,
            'name'    => $name,
            'user'    => $userId,
            'expires' => is_numeric($ttl) && (int) $ttl > 0
                ? now()->getTimestamp() + ((int) $ttl * 60)
                : null,
        ]);
    }

    /**
     * Decrypt a token and verify it was minted for this user and is still fresh.
     * Returns null when the token is unreadable, expired, or someone else's.
     *
     * @return array{disk: string, path: string, name: string}|null
     */
    public static function open(string $token, ?Authenticatable $user): ?array
    {
        try {
            $payload = Crypt::decrypt($token);
        } catch (Throwable) {
            return null;
        }

        if (! is_array($payload) || ! is_string($payload['path'] ?? null)) {
            return null;
        }

        $mintedFor = $payload['user'] ?? null;

        if ($mintedFor !== null && (string) $mintedFor !== (string) $user?->getAuthIdentifier()) {
            return null;
        }

        $expiresAt = $payload['expires'] ?? null;

        if (is_int($expiresAt) && $expiresAt < now()->getTimestamp()) {
            return null;
        }

        return [
            'disk' => is_string($payload['disk'] ?? null)
                ? $payload['disk']
                : (string) config('kinetix.filesystem.private_disk', 'local'),
            'path' => $payload['path'],
            'name' => is_string($payload['name'] ?? null) ? $payload['name'] : 'export',
        ];
    }
}
