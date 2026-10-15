<?php

declare(strict_types=1);

namespace Happones\Kinetix\Markdown;

use Happones\Kinetix\Markdown\Highlighting\CodeHighlighter;
use Happones\Kinetix\Support\SafeMarkdown;
use League\CommonMark\Environment\Environment;
use League\CommonMark\Extension\CommonMark\CommonMarkCoreExtension;
use League\CommonMark\Extension\GithubFlavoredMarkdownExtension;
use League\CommonMark\MarkdownConverter;

/**
 * Kinetix's markdown for content an app ships (help articles, docs pages):
 * GitHub-flavored, safe to put in a page (raw HTML stripped, unsafe links
 * dropped, like {@see SafeMarkdown}), plus callouts,
 * highlighted code and code groups, heading ids and a table of contents —
 * see {@see KinetixMarkdownExtension}.
 *
 *     $page = app(MarkdownRenderer::class)->render($markdown, 'es', anchors: true);
 *     $page->html; $page->toc; $page->text;
 *
 * One converter is built per language (its labels are translated) and reused.
 */
class MarkdownRenderer
{
    /** @var array<string, MarkdownConverter> */
    protected array $converters = [];

    public function __construct(protected ?CodeHighlighter $highlighter = null) {}

    /**
     * @param bool $anchors Add a `#` permalink to every h2–h4 (docs pages).
     */
    public function render(string $markdown, ?string $locale = null, bool $anchors = false): RenderedMarkdown
    {
        $locale ??= app()->getLocale();
        $result = $this->converter($locale, $anchors)->convert($markdown);
        $html   = (string) $result->getContent();
        $toc    = $result->getDocument()->data->get('kinetix_toc', []);

        return new RenderedMarkdown($html, is_array($toc) ? $toc : [], $this->plainText($html));
    }

    protected function converter(string $locale, bool $anchors): MarkdownConverter
    {
        $key = $locale.($anchors ? ':anchors' : '');

        if (isset($this->converters[$key])) {
            return $this->converters[$key];
        }

        $environment = new Environment([
            'html_input'         => 'strip',
            'allow_unsafe_links' => false,
        ]);

        $environment->addExtension(new CommonMarkCoreExtension);
        $environment->addExtension(new GithubFlavoredMarkdownExtension);
        $environment->addExtension(new KinetixMarkdownExtension($this->labels($locale), $this->highlighter, $anchors));

        return $this->converters[$key] = new MarkdownConverter($environment);
    }

    /**
     * @return array<string, string>
     */
    protected function labels(string $locale): array
    {
        $labels = [];

        foreach ([...KinetixMarkdownExtension::CALLOUTS, 'copy', 'examples', 'anchor', 'table'] as $name) {
            $labels[$name] = $this->translate("markdown_{$name}", $locale);
        }

        return $labels;
    }

    /**
     * The host's published copy of a key wins; Kinetix's own is the fallback.
     */
    protected function translate(string $key, string $locale): string
    {
        $published = "kinetix.{$key}";

        return (string) (trans()->has($published, $locale) ? __($published, [], $locale) : __("kinetix::{$published}", [], $locale));
    }

    /**
     * The document as one line of text: what search reads. Permalinks and
     * the code blocks' labels and buttons (tabs included) aren't content.
     */
    protected function plainText(string $html): string
    {
        $html = (string) preg_replace('/<a [^>]*class="kx-md-anchor"[^>]*>.*?<\/a>|<span class="kx-code-label">.*?<\/span>|<button\b.*?<\/button>/s', ' ', $html);
        $text = html_entity_decode(strip_tags(str_replace('<', ' <', $html)), ENT_QUOTES|ENT_HTML5);

        return trim((string) preg_replace('/\s+/u', ' ', $text));
    }
}
