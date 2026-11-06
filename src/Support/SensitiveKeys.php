<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

/**
 * Which array keys name a secret, and redacting their values at any depth.
 *
 * A key matches when, lower-cased, it CONTAINS one of the {@see SUBSTRINGS}
 * (or an `$extra` entry), or when one of its segments — split on
 * non-alphanumerics and camelCase boundaries — is one of the
 * {@see SEGMENTS}. So `client_secret`, `access_token`, `apiKey`, `x-api-key`,
 * `idempotency_key`, `x-auth` and `user_pwd` match, while `keyword`,
 * `monkey`, `author`, `passport` and `compass` don't (`auth` and `pass`
 * are never matched as substrings).
 * The rule errs on the side of hiding: `token_count` or `key_results` are
 * redacted too.
 *
 * @internal
 */
final class SensitiveKeys
{
    /** What a redacted value becomes. */
    public const REDACTED = '[redacted]';

    /** Deeper arrays than this are replaced whole. */
    public const MAX_DEPTH = 32;

    /** @var list<string> */
    private const SUBSTRINGS = [
        'password', 'passwd', 'passphrase', 'secret', 'token', 'authorization', 'cookie', 'credential',
        'signature', 'private_key', 'api_key', 'apikey', 'access_key',
    ];

    /** @var list<string> */
    private const SEGMENTS = ['key', 'keys', 'pin', 'otp', 'ssn', 'cvv', 'cvc', 'pwd', 'bearer', 'jwt', 'auth'];

    /**
     * Whether `$key` names a secret.
     *
     * @param list<string> $extra more lower-case substrings (config additions)
     */
    public static function matches(string|int $key, array $extra = []): bool
    {
        if (is_int($key)) {
            return false;
        }

        $lower = strtolower($key);

        foreach ([...self::SUBSTRINGS, ...$extra] as $needle) {
            $needle = strtolower((string) $needle);

            if ($needle !== '' && str_contains($lower, $needle)) {
                return true;
            }
        }

        $spaced   = (string) preg_replace('/(?<=[a-z0-9])(?=[A-Z])/', ' ', $key);
        $segments = preg_split('/[^a-z0-9]+/', strtolower($spaced), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return array_intersect($segments, self::SEGMENTS) !== [];
    }

    /**
     * `$data` with the value of every matching key replaced by
     * {@see REDACTED}, recursively (list items too).
     *
     * @param  array<array-key, mixed> $data
     * @param  list<string>            $extra more lower-case substrings (config additions)
     * @return array<array-key, mixed>
     */
    public static function redact(array $data, array $extra = []): array
    {
        return self::redactLevel($data, $extra, 1);
    }

    /**
     * @param  array<array-key, mixed> $data
     * @param  list<string>            $extra
     * @return array<array-key, mixed>
     */
    private static function redactLevel(array $data, array $extra, int $depth): array
    {
        foreach ($data as $key => $value) {
            if (self::matches($key, $extra)) {
                $data[$key] = self::REDACTED;

                continue;
            }

            if (is_array($value)) {
                $data[$key] = $depth >= self::MAX_DEPTH ? self::REDACTED : self::redactLevel($value, $extra, $depth + 1);
            }
        }

        return $data;
    }
}
