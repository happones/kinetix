<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Markdown\FrontMatter;
use Happones\Kinetix\Markdown\HeadingSlug;
use Happones\Kinetix\Markdown\Highlighting\CodeHighlighter;
use Happones\Kinetix\Markdown\Highlighting\PhikiHighlighter;
use Happones\Kinetix\Markdown\MarkdownRenderer;
use Happones\Kinetix\Markdown\RenderedMarkdown;
use Happones\Kinetix\Tests\TestCase;

class MarkdownRendererTest extends TestCase
{
    private function render(string $markdown, string $locale = 'en', bool $anchors = false, ?CodeHighlighter $highlighter = null): RenderedMarkdown
    {
        return (new MarkdownRenderer($highlighter))->render($markdown, $locale, $anchors);
    }

    public function test_an_alert_becomes_a_translated_callout(): void
    {
        $html = $this->render("> [!WARNING]\n> Back up first.", 'es')->html;

        $this->assertStringContainsString('<div class="kx-callout kx-callout-warning" role="note">', $html);
        $this->assertStringContainsString('Advertencia</p>', $html);
        $this->assertStringContainsString('<p>Back up first.</p>', $html);
        $this->assertStringNotContainsString('<blockquote', $html);
    }

    public function test_text_after_the_marker_titles_the_callout_and_a_plain_quote_stays_a_quote(): void
    {
        $html = $this->render("> [!TIP] Faster builds\n> Cache the vendor folder.\n\n> Just a quote.")->html;

        $this->assertStringContainsString('Faster builds</p>', $html);
        $this->assertStringNotContainsString('>Tip</p>', $html);
        $this->assertStringContainsString("<blockquote>\n<p>Just a quote.</p>", $html);
    }

    public function test_a_code_block_gets_a_label_and_a_copy_button_and_stays_escaped(): void
    {
        $html = $this->render("```php\necho '<b>';\n```")->html;

        $this->assertStringContainsString('<div class="kx-code" data-lang="php"><div class="kx-code-bar"><span class="kx-code-label">php</span>', $html);
        $this->assertStringContainsString('data-kx-copy aria-label="Copy code"', $html);
        $this->assertStringContainsString('<code class="language-php">echo \'&lt;b&gt;\';</code>', $html);
    }

    public function test_adjacent_titled_blocks_become_tabs_with_one_panel_visible(): void
    {
        $html = $this->render("```bash [npm]\nnpm i\n```\n\n```bash [yarn]\nyarn add\n```\n\n```bash [pnpm]\npnpm add\n```")->html;

        $this->assertSame(1, substr_count($html, 'class="kx-code-group"'));
        $this->assertSame(3, substr_count($html, 'role="tab"'));
        $this->assertSame(1, substr_count($html, 'aria-selected="true"'));
        $this->assertSame(2, substr_count($html, ' hidden>'));
        $this->assertMatchesRegularExpression('/id="(kx-code-[a-f0-9]+-1-0)" aria-controls="\1-panel"/', $html);
        $this->assertStringContainsString('aria-label="Code examples"', $html);
        // One copy button for the whole group, in the tab row.
        $this->assertSame(1, substr_count($html, 'data-kx-copy'));
    }

    public function test_a_lone_titled_block_is_not_a_group(): void
    {
        $html = $this->render("```bash [Install]\nnpm i\n```")->html;

        $this->assertStringNotContainsString('kx-code-group', $html);
        $this->assertStringContainsString('<span class="kx-code-label">Install</span>', $html);
    }

    public function test_headings_get_unicode_ids_and_h2_h3_make_the_toc(): void
    {
        $rendered = $this->render("# Guía\n\n## Configuración\n\n### Шаг один\n\n#### Deep\n\n## Configuración");

        $this->assertStringContainsString('<h2 id="configuracion">', $rendered->html);
        $this->assertStringContainsString('<h3 id="шаг-один">', $rendered->html);
        $this->assertSame([
            ['level' => 2, 'id' => 'configuracion', 'text' => 'Configuración'],
            ['level' => 3, 'id' => 'шаг-один', 'text' => 'Шаг один'],
            ['level' => 2, 'id' => 'configuracion-2', 'text' => 'Configuración'],
        ], $rendered->toc);
    }

    public function test_anchors_add_a_labelled_permalink_that_search_text_ignores(): void
    {
        $rendered = $this->render("## Install\n\nRun it.", anchors: true);

        $this->assertStringContainsString('<a class="kx-md-anchor" aria-label="Link to this section: Install" href="#install">#</a>', $rendered->html);
        $this->assertSame('Install Run it.', $rendered->text);
        $this->assertStringNotContainsString('kx-md-anchor', $this->render('## Install')->html);
    }

    public function test_a_table_scrolls_inside_a_labelled_region(): void
    {
        $html = $this->render("| a | b |\n|---|---|\n| 1 | 2 |")->html;

        $this->assertMatchesRegularExpression('/<div class="kx-table-scroll" role="region" tabindex="0" aria-label="Table"><table>/', $html);
    }

    public function test_raw_html_and_unsafe_links_are_dropped(): void
    {
        $html = $this->render("<script>alert(1)</script>\n\nHi <img src=x onerror=alert(1)> [x](javascript:alert(1))")->html;

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('javascript:', $html);
    }

    public function test_the_search_text_leaves_the_buttons_out(): void
    {
        $this->assertSame('Intro echo 1;', $this->render("Intro\n\n```php\necho 1;\n```")->text);
    }

    public function test_phiki_highlights_with_both_themes_and_falls_back_for_unknown_languages(): void
    {
        $highlighter = new PhikiHighlighter;
        $html        = $this->render("```php\n\$a = 1;\n```\n\n```nope\nplain\n```", highlighter: $highlighter)->html;

        $this->assertStringContainsString('<pre class="phiki language-php', $html);
        $this->assertStringContainsString('--phiki-dark-color', $html);
        $this->assertStringContainsString('<pre class="kx-code-plain"><code class="language-nope">plain</code></pre>', $html);
    }

    public function test_the_container_renderer_highlights_only_when_enabled(): void
    {
        $this->assertStringContainsString('class="phiki', app(MarkdownRenderer::class)->render("```php\n1;\n```")->html);

        config()->set('kinetix.markdown.highlight.enabled', false);
        app()->forgetInstance(MarkdownRenderer::class);

        $this->assertStringNotContainsString('class="phiki', app(MarkdownRenderer::class)->render("```php\n1;\n```")->html);
    }

    public function test_a_heading_slug_folds_latin_accents_only(): void
    {
        $this->assertSame('configuracion-avanzada', HeadingSlug::from('Configuración avanzada!'));
        $this->assertSame('إعداد', HeadingSlug::from('إعداد'));
        $this->assertSame('设置-api', HeadingSlug::from('设置 API'));
    }

    public function test_front_matter_is_flat_pairs(): void
    {
        $markdown = "---\ntitle: \"Hello: world\"\norder: 2\nempty:\n---\n# Body";

        $this->assertSame(['title' => 'Hello: world', 'order' => '2'], FrontMatter::parse($markdown));
        $this->assertSame('# Body', FrontMatter::strip($markdown));
    }
}
