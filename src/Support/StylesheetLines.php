<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

/**
 * Line-level edits of a host stylesheet that keep its formatting and its
 * structure: the original line endings survive, and new lines only ever land
 * at the top level, right after a COMPLETE statement.
 *
 * The scan understands just enough CSS for that: `/* … *\/` comments (also
 * across lines) and quoted strings are skipped, `{ … }` blocks are tracked by
 * depth, and a statement ends at a top-level `;`. A line qualifies as the
 * insertion point only when, at its end, nothing is open (no comment, string,
 * block or half-written statement) and a wanted directive was the last
 * statement to finish; the first such line after it wins, so blank lines and
 * comments below are left where they are.
 */
class StylesheetLines
{
    /** The UTF-8 byte-order mark. */
    protected const BOM = "\xEF\xBB\xBF";

    /**
     * The line ending the stylesheet uses (`\r\n` when it has any, else `\n`).
     */
    public static function eol(string $css): string
    {
        return str_contains($css, "\r\n") ? "\r\n" : "\n";
    }

    /**
     * The stylesheet split into lines, whatever its line endings.
     *
     * @return list<string>
     */
    public static function split(string $css): array
    {
        return preg_split('/\r\n|\n|\r/', self::withoutBom($css)) ?: [];
    }

    /**
     * The UTF-8 byte-order mark the stylesheet starts with, or ''. It is not
     * part of the first statement and must stay the file's very first bytes.
     */
    public static function bom(string $css): string
    {
        return str_starts_with($css, self::BOM) ? self::BOM : '';
    }

    protected static function withoutBom(string $css): string
    {
        return self::bom($css) === '' ? $css : substr($css, strlen(self::BOM));
    }

    /**
     * `$css` with `$insert` placed after the last top-level `$directives`
     * statement (at the top when the stylesheet has none), in the file's own
     * line endings. Null when no safe place exists: the caller must not write.
     *
     * @param list<string> $insert
     * @param list<string> $directives e.g. ['@import', '@source']
     */
    public static function insertAfterLast(string $css, array $insert, array $directives): ?string
    {
        $lines  = self::split($css);
        $anchor = self::scan($lines, $directives)['anchor'];

        if ($anchor === null) {
            return null;
        }

        array_splice($lines, $anchor + 1, 0, $insert);

        return self::bom($css).implode(self::eol($css), $lines);
    }

    /**
     * The 0-based lines where a `$directives` statement starts INSIDE a block
     * (`@source` within `@theme { … }`), which Tailwind rejects.
     *
     * @param  list<string> $directives
     * @return list<int>
     */
    public static function nestedDirectives(string $css, array $directives): array
    {
        return self::scan(self::split($css), $directives)['nested'];
    }

    /**
     * Walk the stylesheet once.
     *
     * `anchor` is the index of the line to insert after: -1 (the top) when no
     * top-level `$directives` statement exists, null when one exists but no
     * line after it is a safe place.
     *
     * @param  list<string>                               $lines
     * @param  list<string>                               $directives
     * @return array{anchor: int|null, nested: list<int>}
     */
    public static function scan(array $lines, array $directives): array
    {
        $inComment     = false;
        $quote         = null;
        $depth         = 0;
        $statement     = '';
        $statementLine = 0;
        $pending       = false;
        $sawWanted     = false;
        $anchor        = null;
        $top           = -1;
        $statements    = 0;
        $charsetOpen   = false;
        $nested        = [];

        foreach ($lines as $index => $line) {
            $length = strlen($line);

            for ($at = 0; $at < $length; $at++) {
                $char = $line[$at];

                if ($inComment) {
                    if ($char === '*' && ($line[$at + 1] ?? '') === '/') {
                        $inComment = false;
                        $at++;
                    }

                    continue;
                }

                if ($quote !== null) {
                    if ($char === '\\') {
                        $at++;

                        continue;
                    }

                    if ($char === $quote) {
                        $quote = null;
                    }

                    $statement .= $char;

                    continue;
                }

                if ($char === '/' && ($line[$at + 1] ?? '') === '*') {
                    $inComment = true;
                    $at++;

                    continue;
                }

                if (trim($statement) === '' && trim($char) !== '') {
                    $statementLine = $index;
                }

                if ($char === '"' || $char === "'") {
                    $quote = $char;
                    $statement .= $char;

                    continue;
                }

                if ($char === '{') {
                    $depth++;
                    $statement = '';

                    continue;
                }

                if ($char === '}') {
                    $depth     = max(0, $depth - 1);
                    $statement = '';

                    if ($depth === 0) {
                        $pending     = false;
                        $charsetOpen = false;
                        $statements++;
                    }

                    continue;
                }

                if ($char === ';') {
                    $isWanted = self::startsWithAny(ltrim($statement), $directives);

                    if ($depth === 0) {
                        $pending     = $isWanted;
                        $sawWanted   = $sawWanted || $isWanted;
                        $charsetOpen = $statements === 0 && str_starts_with(ltrim($statement), '@charset');
                        $statements++;
                    }

                    if ($depth > 0 && $isWanted) {
                        $nested[] = $statementLine;
                    }

                    $statement = '';

                    continue;
                }

                $statement .= $char;
            }

            $statement .= ' ';

            if ($inComment || $quote !== null || $depth > 0 || trim($statement) !== '') {
                continue;
            }

            if ($charsetOpen) {
                $top         = $index;
                $charsetOpen = false;
            }

            if ($pending) {
                $anchor  = $index;
                $pending = false;
            }
        }

        // Nothing to follow: the top, but never above a leading `@charset`.
        if (! $sawWanted) {
            $anchor = $top;
        }

        return ['anchor' => $anchor, 'nested' => $nested];
    }

    /**
     * @param list<string> $prefixes
     */
    protected static function startsWithAny(string $text, array $prefixes): bool
    {
        foreach ($prefixes as $prefix) {
            if (str_starts_with($text, $prefix)) {
                return true;
            }
        }

        return false;
    }
}
