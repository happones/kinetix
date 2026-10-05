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

    public function test_line_breaks_mode_keeps_single_newlines(): void
    {
        $this->assertStringContainsString('<br />', SafeMarkdown::toHtml("line one\nline two", lineBreaks: true));
        $this->assertStringNotContainsString('<br />', SafeMarkdown::toHtml("line one\nline two"));
    }
}
