<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use League\CommonMark\GithubFlavoredMarkdownConverter;

/**
 * Markdown to HTML that is safe to put in a page with `v-html`: raw HTML in
 * the source is stripped and `javascript:` / `data:` links are dropped, so an
 * editor's text can format itself but never run script.
 *
 * GitHub-flavored (tables, strikethrough, autolinks, task lists). The two
 * converters are built once per process and reused — building one is the
 * expensive part, and a page payload can carry several bodies.
 */
class SafeMarkdown
{
    /** @var array<string, GithubFlavoredMarkdownConverter> */
    protected static array $converters = [];

    /**
     * @param bool $lineBreaks Render a single newline as a line break, the
     *                         way a plain-text field already displayed it.
     */
    public static function toHtml(string $markdown, bool $lineBreaks = false): string
    {
        return (string) static::converter($lineBreaks)->convert($markdown);
    }

    protected static function converter(bool $lineBreaks): GithubFlavoredMarkdownConverter
    {
        $key = $lineBreaks ? 'breaks' : 'plain';

        return static::$converters[$key] ??= new GithubFlavoredMarkdownConverter([
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
            ...($lineBreaks ? ['renderer' => ['soft_break' => "<br />\n"]] : []),
        ]);
    }
}
