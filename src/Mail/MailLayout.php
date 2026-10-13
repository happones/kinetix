<?php

declare(strict_types=1);

namespace Happones\Kinetix\Mail;

use Illuminate\Mail\Mailables\Content;

/**
 * Puts the emails Kinetix builds from HTML — a mail template's body, a
 * scheduled report's note — inside the app's markdown mail layout
 * (`<x-mail::message>`), so they carry the same header, footer and theme as
 * every other email the app sends. `kinetix.mail.layout` = false sends the
 * bare HTML instead.
 */
final class MailLayout
{
    public const VIEW = 'kinetix::mail.html-message';

    public static function enabled(): bool
    {
        return (bool) config('kinetix.mail.layout', true);
    }

    /**
     * The mailable content for an HTML body.
     */
    public static function content(string $html): Content
    {
        return self::enabled()
            ? new Content(markdown: self::VIEW, with: ['body' => self::htmlBlock($html)])
            : new Content(htmlString: $html);
    }

    /**
     * The layout reads its slot as markdown, where a blank line ends an HTML
     * block and the indented lines after it become a code block. Wrapped in a
     * `<div>` with every blank line filled by an empty comment, the body is a
     * single HTML block that markdown passes through untouched — a `<pre>`
     * keeps its line breaks, since the comment renders nothing.
     */
    public static function htmlBlock(string $html): string
    {
        $html = (string) preg_replace('/^[ \t]*$/m', '<!---->', trim($html));

        return "<div>\n{$html}\n</div>";
    }
}
