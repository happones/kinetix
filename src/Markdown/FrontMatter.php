<?php

declare(strict_types=1);

namespace Happones\Kinetix\Markdown;

/**
 * Flat `key: value` front matter between `---` fences, the way Kinetix's
 * markdown content (help articles, docs pages) declares its metadata. No YAML
 * dependency: nested values aren't supported, quotes around a value are
 * dropped, empty values are skipped.
 */
final class FrontMatter
{
    /**
     * @return array<string, string>
     */
    public static function parse(string $markdown): array
    {
        if (preg_match('/^---\R(.*?)\R---\R?/s', $markdown, $matches) !== 1) {
            return [];
        }

        $meta = [];

        foreach (preg_split('/\R/', $matches[1]) ?: [] as $line) {
            if (! str_contains($line, ':')) {
                continue;
            }

            [$key, $value] = explode(':', $line, 2);
            $key           = trim($key);
            $value         = trim(trim($value), '"\'');

            if ($key !== '' && $value !== '') {
                $meta[$key] = $value;
            }
        }

        return $meta;
    }

    public static function strip(string $markdown): string
    {
        return (string) preg_replace('/^---\R.*?\R---\R?/s', '', $markdown);
    }
}
