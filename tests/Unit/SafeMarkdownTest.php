<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Support\SafeMarkdown;
use PHPUnit\Framework\TestCase;

/**
 * SafeMarkdown feeds `v-html`: formatting must render, script must not.
 */
class SafeMarkdownTest extends TestCase
{
    public function test_it_renders_github_flavored_markdown(): void
    {
        $html = SafeMarkdown::toHtml("**Bold**, _italic_, ~~gone~~ and [docs](/docs).\n\n- one\n- two");

        $this->assertStringContainsString('<strong>Bold</strong>', $html);
        $this->assertStringContainsString('<em>italic</em>', $html);
        $this->assertStringContainsString('<del>gone</del>', $html);
        $this->assertStringContainsString('<a href="/docs">docs</a>', $html);
        $this->assertStringContainsString('<li>two</li>', $html);
    }

    public function test_raw_html_never_reaches_the_page(): void
    {
        $html = SafeMarkdown::toHtml("Hi <script>alert(1)</script>\n\n<img src=x onerror=alert(1)>");

        $this->assertStringNotContainsString('<script', $html);
        $this->assertStringNotContainsString('onerror', $html);
        $this->assertStringNotContainsString('<img', $html);
    }

    public function test_script_links_are_dropped(): void
    {
        $html = SafeMarkdown::toHtml('[click](javascript:alert(1)) and [data](data:text/html,x)');

        $this->assertStringNotContainsString('javascript:', $html);
        $this->assertStringNotContainsString('data:text', $html);
    }

    public function test_the_code_inside_a_stripped_script_goes_with_its_tags(): void
    {
        $html = SafeMarkdown::toHtml('Rotate your keys <script>window.pwned = 1</script><style>body{}</style> today.');

        $this->assertStringNotContainsString('window.pwned', $html);
        $this->assertStringNotContainsString('body{}', $html);
        $this->assertStringContainsString('Rotate your keys', $html);
        $this->assertStringContainsString('today.', $html);
    }

    public function test_an_unsafe_link_becomes_its_text_not_a_dead_anchor(): void
    {
        $html = SafeMarkdown::toHtml('See [the **bad** one](javascript:alert(1)) and [docs](/docs).');

        $this->assertStringContainsString('the <strong>bad</strong> one', $html);
        $this->assertStringNotContainsString('<a>', $html);
        $this->assertSame(1, substr_count($html, '<a'));
        $this->assertStringContainsString('<a href="/docs">docs</a>', $html);
    }

    public function test_a_script_shown_as_code_is_kept(): void
    {
        // Documentation about the tag is not the tag.
        $html = SafeMarkdown::toHtml('Never paste `<script>alert(1)</script>` into a field.');

        $this->assertStringContainsString('<code>&lt;script&gt;alert(1)&lt;/script&gt;</code>', $html);
    }

    public function test_line_breaks_mode_keeps_single_newlines(): void
    {
        $this->assertStringContainsString('<br />', SafeMarkdown::toHtml("line one\nline two", lineBreaks: true));
        $this->assertStringNotContainsString('<br />', SafeMarkdown::toHtml("line one\nline two"));
    }
}
