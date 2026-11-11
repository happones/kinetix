<?php

declare(strict_types=1);

namespace Happones\Kinetix\Query;

/**
 * Normalizes the free-text term every Kinetix search endpoint receives.
 *
 * Reads `kinetix.search.*` on each call, so a config change applies to
 * cached routes too. A term is only ever truncated, never rejected: an
 * over-long paste still searches, just on its first `max_length` characters.
 */
final class SearchTerm
{
    /**
     * The usable term: a string (ints and floats are cast; anything else is empty), control characters
     * (tabs, newlines, NUL…) turned into a single space so words stay apart, trimmed and cut to `kinetix.search.max_length` characters.
     */
    public static function from(mixed $raw): string
    {
        if (is_int($raw) || is_float($raw)) {
            $raw = (string) $raw;
        }

        if (! is_string($raw)) {
            return '';
        }

        $clean = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $raw);
        $clean = is_string($clean) ? preg_replace('/ {2,}/', ' ', $clean) : null;

        $term = trim(is_string($clean) ? $clean : '');
        $max  = self::maxLength();

        return $max !== null && mb_strlen($term) > $max ? trim(mb_substr($term, 0, $max)) : $term;
    }

    /** The shortest non-empty term worth a query (at least 1). */
    public static function minChars(): int
    {
        $value = config('kinetix.search.min_chars', 1);

        return is_numeric($value) ? max(1, (int) $value) : 1;
    }

    /** The character cap, or null when the cap is off. */
    public static function maxLength(): ?int
    {
        $value = config('kinetix.search.max_length', 100);

        if (! is_numeric($value) || (int) $value < 1) {
            return null;
        }

        return (int) $value;
    }

    /** Whether a term is non-empty yet shorter than the minimum. */
    public static function isBelowMinimum(string $term): bool
    {
        return $term !== '' && mb_strlen($term) < self::minChars();
    }
}
