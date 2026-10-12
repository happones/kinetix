<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use League\CommonMark\Environment\Environment;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\CommonMark\Node\Inline\HtmlInline;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;
use League\CommonMark\Util\RegexHelper;

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
    /** @var array<string, MarkdownConverter> */
    protected static array $converters = [];

    /**
     * @param bool $lineBreaks Render a single newline as a line break, the
     *                         way a plain-text field already displayed it.
     */
    public static function toHtml(string $markdown, bool $lineBreaks = false): string
    {
        return (string) static::converter($lineBreaks)->convert($markdown);
    }

    protected static function converter(bool $lineBreaks): MarkdownConverter
    {
        $key = $lineBreaks ? 'breaks' : 'plain';

        if (! isset(static::$converters[$key])) {
            $environment = new Environment([
                'html_input'         => 'strip',
                'allow_unsafe_links' => false,
                ...($lineBreaks ? ['renderer' => ['soft_break' => "<br />\n"]] : []),
            ]);

            $environment->addExtension(new CommonMarkCoreExtension);
            $environment->addExtension(new GithubFlavoredMarkdownExtension);
            $environment->addEventListener(DocumentParsedEvent::class, [static::class, 'scrub']);

            static::$converters[$key] = new MarkdownConverter($environment);
        }

        return static::$converters[$key];
    }

    /**
     * What stripping alone leaves behind, tidied before rendering.
     *
     * - Stripping removes an inline `<script>` / `<style>` TAG, not what is
     *   between the tags, so the code itself showed up as text. It goes too.
     * - An unsafe link loses its `href` but stayed an `<a>`, styled and
     *   focusable like a link that leads nowhere. It becomes its text.
     */
    public static function scrub(DocumentParsedEvent $event): void
    {
        $walker  = $event->getDocument()->walker();
        $links   = [];
        $scripts = [];

        while ($step = $walker->next()) {
            if (! $step->isEntering()) {
                continue;
            }

            $node = $step->getNode();

            if ($node instanceof Link && RegexHelper::isLinkPotentiallyUnsafe($node->getUrl())) {
                $links[] = $node;
            }

            if ($node instanceof HtmlInline && preg_match('#^<(script|style)\b#i', $node->getLiteral(), $match) === 1) {
                $scripts[] = [$node, strtolower($match[1])];
            }
        }

        foreach ($scripts as [$open, $tag]) {
            $next = $open->next();

            while ($next !== null && ! ($next instanceof HtmlInline && preg_match('#^</'.$tag.'\s*>#i', $next->getLiteral()) === 1)) {
                $after = $next->next();
                $next->detach();
                $next = $after;
            }
        }

        foreach ($links as $link) {
            foreach ([...$link->children()] as $child) {
                $link->insertBefore($child);
            }

            $link->detach();
        }
    }
}
