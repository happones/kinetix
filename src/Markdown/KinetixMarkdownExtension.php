<?php

declare(strict_types=1);

namespace Happones\Kinetix\Markdown;

use Happones\Kinetix\Markdown\Highlighting\CodeHighlighter;
use Happones\Kinetix\Markdown\Nodes\Callout;
use Happones\Kinetix\Markdown\Nodes\CodeGroup;
use Happones\Kinetix\Markdown\Nodes\TableScroll;
use Happones\Kinetix\Support\SafeMarkdown;
use InvalidArgumentException;
use League\CommonMark\Environment\EnvironmentBuilderInterface;
use League\CommonMark\Event\DocumentParsedEvent;
use League\CommonMark\Extension\CommonMark\Node\Block\BlockQuote;
use League\CommonMark\Extension\CommonMark\Node\Block\FencedCode;
use League\CommonMark\Extension\CommonMark\Node\Block\Heading;
use League\CommonMark\Extension\CommonMark\Node\Inline\Link;
use League\CommonMark\Extension\ExtensionInterface;
use League\CommonMark\Extension\Table\Table;
use League\CommonMark\Node\Block\Paragraph;
use League\CommonMark\Node\Inline\Newline;
use League\CommonMark\Node\Inline\Text;
use League\CommonMark\Node\Node;
use League\CommonMark\Node\StringContainerHelper;
use League\CommonMark\Renderer\ChildNodeRendererInterface;
use League\CommonMark\Renderer\NodeRendererInterface;
use League\CommonMark\Util\Xml;

/**
 * What Kinetix adds to GitHub-flavored markdown, for help articles and docs:
 *
 * - `> [!NOTE]` / `[!TIP]` / `[!IMPORTANT]` / `[!WARNING]` / `[!CAUTION]`
 *   become callouts (text after the marker replaces the title);
 * - fenced code is highlighted when a {@see CodeHighlighter} is bound, with a
 *   label and a copy button; adjacent titled blocks (```bash [npm]) become a
 *   tabbed code group;
 * - headings get stable, Unicode-aware ids (and, for docs, a permalink), and
 *   h2/h3 make the table of contents;
 * - tables scroll on their own instead of widening a phone.
 *
 * The labels it prints (callout titles, "Copy code"…) come translated in, so
 * one extension serves one language.
 */
class KinetixMarkdownExtension implements ExtensionInterface, NodeRendererInterface
{
    public const CALLOUTS = ['note', 'tip', 'important', 'warning', 'caution'];

    protected const ICONS = [
        'note'      => '<circle cx="12" cy="12" r="10"/><path d="M12 16v-4"/><path d="M12 8h.01"/>',
        'tip'       => '<path d="M15 14c.2-1 .7-1.7 1.5-2.5 1-.9 1.5-2.2 1.5-3.5A6 6 0 0 0 6 8c0 1 .2 2.2 1.5 3.5.7.7 1.3 1.5 1.5 2.5"/><path d="M9 18h6"/><path d="M10 22h4"/>',
        'important' => '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/><path d="M12 7v2"/><path d="M12 13h.01"/>',
        'warning'   => '<path d="m21.73 18-8-14a2 2 0 0 0-3.48 0l-8 14A2 2 0 0 0 4 21h16a2 2 0 0 0 1.73-3"/><path d="M12 9v4"/><path d="M12 17h.01"/>',
        'caution'   => '<path d="M12 16h.01"/><path d="M12 8v4"/><path d="M15.312 2a2 2 0 0 1 1.414.586l4.688 4.688A2 2 0 0 1 22 8.688v6.624a2 2 0 0 1-.586 1.414l-4.688 4.688a2 2 0 0 1-1.414.586H8.688a2 2 0 0 1-1.414-.586l-4.688-4.688A2 2 0 0 1 2 15.312V8.688a2 2 0 0 1 .586-1.414l4.688-4.688A2 2 0 0 1 8.688 2z"/>',
        'copy'      => '<rect width="14" height="14" x="8" y="8" rx="2" ry="2"/><path d="M4 16c-1.1 0-2-.9-2-2V4c0-1.1.9-2 2-2h10c1.1 0 2 .9 2 2"/>',
        'check'     => '<path d="M20 6 9 17l-5-5"/>',
    ];

    /**
     * @param array<string, string> $labels callout titles by type, plus `copy`,
     *                                      `examples`, `anchor` and `table`
     */
    public function __construct(
        protected array $labels,
        protected ?CodeHighlighter $highlighter = null,
        protected bool $anchors = false,
    ) {}

    public function register(EnvironmentBuilderInterface $environment): void
    {
        $environment
            ->addRenderer(FencedCode::class, $this, 10)
            ->addRenderer(CodeGroup::class, $this)
            ->addRenderer(Callout::class, $this)
            ->addRenderer(TableScroll::class, $this)
            ->addEventListener(DocumentParsedEvent::class, [SafeMarkdown::class, 'scrub'])
            ->addEventListener(DocumentParsedEvent::class, [$this, 'onDocumentParsed']);
    }

    public function onDocumentParsed(DocumentParsedEvent $event): void
    {
        $document = $event->getDocument();
        $quotes   = [];
        $codes    = [];
        $headings = [];
        $tables   = [];

        foreach ($document->iterator() as $node) {
            match (true) {
                $node instanceof BlockQuote => $quotes[]   = $node,
                $node instanceof FencedCode => $codes[]    = $node,
                $node instanceof Heading    => $headings[] = $node,
                $node instanceof Table      => $tables[]   = $node,
                default                     => null,
            };
        }

        foreach ($quotes as $quote) {
            $this->convertCallout($quote);
        }

        $this->groupCode($codes);

        foreach ($tables as $table) {
            $scroll = new TableScroll;
            $table->insertBefore($scroll);
            $scroll->appendChild($table);
        }

        $document->data->set('kinetix_toc', $this->identifyHeadings($headings));
    }

    public function render(Node $node, ChildNodeRendererInterface $childRenderer): string
    {
        return match (true) {
            $node instanceof FencedCode  => $this->renderCode($node),
            $node instanceof CodeGroup   => $this->renderGroup($node, $childRenderer),
            $node instanceof Callout     => $this->renderCallout($node, $childRenderer),
            $node instanceof TableScroll => '<div class="kx-table-scroll" role="region" tabindex="0" aria-label="'.Xml::escape($this->labels['table']).'">'
                .$childRenderer->renderNodes($node->children()).'</div>',
            default => throw new InvalidArgumentException('Unsupported node: '.$node::class),
        };
    }

    /**
     * The language and `[Title]` of a fence's info string (```bash [npm]).
     *
     * @return array{language: string, title: string}
     */
    public static function fenceInfo(string $info): array
    {
        preg_match('/\[([^\]]+)\]/', $info, $title);
        $language = strtolower((string) strtok(trim((string) preg_replace('/\[[^\]]*\]/', '', $info)), " \t"));

        return [
            'language' => (string) preg_replace('/[^a-z0-9_+#.-]/', '', $language),
            'title'    => trim($title[1] ?? ''),
        ];
    }

    protected function convertCallout(BlockQuote $quote): void
    {
        $paragraph = $quote->firstChild();

        if (! $paragraph instanceof Paragraph) {
            return;
        }

        // The marker can arrive split across text nodes ("[", "!NOTE]") and
        // ends at the first line break.
        $marker = '';
        $nodes  = [];

        foreach ($paragraph->children() as $child) {
            if ($child instanceof Newline) {
                $nodes[] = $child;

                break;
            }

            if (! $child instanceof Text) {
                return;
            }

            $marker .= $child->getLiteral();
            $nodes[] = $child;
        }

        if (preg_match('/^\[!('.implode('|', self::CALLOUTS).')\]\s*(.*)$/i', $marker, $matches) !== 1) {
            return;
        }

        foreach ($nodes as $node) {
            $node->detach();
        }

        if ($paragraph->firstChild() === null) {
            $paragraph->detach();
        }

        $type    = strtolower($matches[1]);
        $callout = new Callout($type, trim($matches[2]) !== '' ? trim($matches[2]) : $this->labels[$type]);

        foreach ([...$quote->children()] as $child) {
            $callout->appendChild($child);
        }

        $quote->replaceWith($callout);
    }

    /**
     * @param array<int, FencedCode> $codes
     */
    protected function groupCode(array $codes): void
    {
        $titled = static fn (?Node $node): bool => $node instanceof FencedCode
            && self::fenceInfo($node->getInfo() ?? '')['title'] !== '';
        $count = 0;

        foreach ($codes as $code) {
            // Only the first block of a run starts a group.
            if (! $titled($code) || $titled($code->previous()) || ! $titled($code->next())) {
                continue;
            }

            $run = [$code];

            for ($next = $code->next(); $next instanceof FencedCode && $titled($next); $next = $next->next()) {
                $run[] = $next;
            }

            $id    = 'kx-code-'.substr(md5(implode("\0", array_map(static fn (FencedCode $block): string => $block->getLiteral(), $run))), 0, 8).'-'.++$count;
            $group = new CodeGroup($id);
            $code->insertBefore($group);

            foreach ($run as $index => $member) {
                $member->data->set('kinetix_panel', ['id' => "{$id}-{$index}", 'selected' => $index === 0]);
                $group->appendChild($member);
            }
        }
    }

    /**
     * @param  array<int, Heading>                                     $headings
     * @return array<int, array{level: int, id: string, text: string}>
     */
    protected function identifyHeadings(array $headings): array
    {
        $taken = [];
        $toc   = [];

        foreach ($headings as $heading) {
            $text = trim(StringContainerHelper::getChildText($heading));
            $id   = HeadingSlug::unique($text, $taken);

            $heading->data->set('attributes/id', $id);

            if ($this->anchors && $heading->getLevel() >= 2) {
                $anchor = new Link('#'.$id, '#');
                $anchor->data->set('attributes', [
                    'class'      => 'kx-md-anchor',
                    'aria-label' => $this->labels['anchor'].': '.$text,
                ]);
                $heading->appendChild($anchor);
            }

            if (in_array($heading->getLevel(), [2, 3], true)) {
                $toc[] = ['level' => $heading->getLevel(), 'id' => $id, 'text' => $text];
            }
        }

        return $toc;
    }

    protected function renderCode(FencedCode $node): string
    {
        ['language' => $language, 'title' => $title] = self::fenceInfo($node->getInfo() ?? '');

        $code = rtrim($node->getLiteral(), "\n");
        $pre  = $this->highlighter?->highlight($code, $language)
            ?? '<pre class="kx-code-plain"><code'.($language !== '' ? ' class="language-'.Xml::escape($language).'"' : '').'>'.Xml::escape($code).'</code></pre>';
        $panel = $node->data->get('kinetix_panel', null);

        if (is_array($panel)) {
            return '<div class="kx-code" data-lang="'.Xml::escape($language).'" role="tabpanel" id="'.$panel['id'].'-panel"'
                .' aria-labelledby="'.$panel['id'].'"'.($panel['selected'] ? '' : ' hidden').'>'.$pre.'</div>';
        }

        $label = $title !== '' ? $title : $language;

        return '<div class="kx-code" data-lang="'.Xml::escape($language).'"><div class="kx-code-bar">'
            .'<span class="kx-code-label">'.Xml::escape($label).'</span>'.$this->copyButton().'</div>'.$pre.'</div>';
    }

    protected function renderGroup(CodeGroup $group, ChildNodeRendererInterface $childRenderer): string
    {
        $tabs = '';

        foreach ($group->children() as $member) {
            if (! $member instanceof FencedCode) {
                continue;
            }

            /** @var array{id: string, selected: bool} $panel */
            $panel = $member->data->get('kinetix_panel');
            $title = self::fenceInfo($member->getInfo() ?? '')['title'];

            $tabs .= '<button type="button" role="tab" class="kx-code-tab" id="'.$panel['id'].'" aria-controls="'.$panel['id'].'-panel"'
                .' aria-selected="'.($panel['selected'] ? 'true' : 'false').'" tabindex="'.($panel['selected'] ? '0' : '-1').'">'
                .Xml::escape($title).'</button>';
        }

        return '<div class="kx-code-group" id="'.$group->id.'"><div class="kx-code-bar">'
            .'<div class="kx-code-tabs" role="tablist" aria-label="'.Xml::escape($this->labels['examples']).'">'.$tabs.'</div>'
            .$this->copyButton().'</div>'.$childRenderer->renderNodes($group->children()).'</div>';
    }

    protected function renderCallout(Callout $callout, ChildNodeRendererInterface $childRenderer): string
    {
        return '<div class="kx-callout kx-callout-'.$callout->type.'" role="note">'
            .'<p class="kx-callout-title">'.$this->icon($callout->type, 'kx-callout-icon').Xml::escape($callout->title).'</p>'
            .$childRenderer->renderNodes($callout->children()).'</div>';
    }

    protected function copyButton(): string
    {
        $label = Xml::escape($this->labels['copy']);

        return '<button type="button" class="kx-code-copy" data-kx-copy aria-label="'.$label.'" title="'.$label.'">'
            .$this->icon('copy', 'kx-code-copy-idle').$this->icon('check', 'kx-code-copy-done').'</button>';
    }

    protected function icon(string $name, string $class): string
    {
        return '<svg class="'.$class.'" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor"'
            .' stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true" focusable="false">'.self::ICONS[$name].'</svg>';
    }
}
