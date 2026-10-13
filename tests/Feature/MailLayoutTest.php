<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Mail\MailLayout;
use Happones\Kinetix\Mail\TemplatedMail;
use Happones\Kinetix\Reports\ScheduledReportMail;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Mail\Mailable;

/**
 * The emails Kinetix builds from HTML go out inside the app's markdown mail
 * layout, like every other email the app sends.
 */
class MailLayoutTest extends TestCase
{
    public function test_a_templated_mail_is_sent_inside_the_app_mail_layout(): void
    {
        $html = $this->rendered(new TemplatedMail('Welcome', '<p>Hello <strong>Ada</strong></p>'));

        $this->assertStringContainsString('class="inner-body"', $html);
        $this->assertStringContainsString('Hello <strong>Ada</strong>', $html);
    }

    public function test_the_body_is_not_read_as_markdown(): void
    {
        $body = "<p>First</p>\n\n    <p>Indented after a blank line</p>\n\n<pre>line one\n\nline three</pre>\n\n*not emphasis*";

        $html = $this->rendered(new TemplatedMail('Welcome', $body));

        $this->assertStringContainsString('<p>Indented after a blank line</p>', $html);
        $this->assertStringNotContainsString('<code>', $html);
        $this->assertStringContainsString("line one\n<!---->\nline three", $html);
        $this->assertStringContainsString('*not emphasis*', $html);
    }

    public function test_a_scheduled_report_note_is_sent_inside_the_layout(): void
    {
        // The app publishes this line; the bare package has only the key.
        app('translator')->addLines(['kinetix.report_mail_intro' => 'Your “:name” report is attached.'], 'en');

        $html = $this->rendered(new ScheduledReportMail('Orders <b>', __FILE__, 'orders.csv'));

        $this->assertStringContainsString('class="inner-body"', $html);
        $this->assertStringContainsString('Orders &lt;b&gt;', $html);
    }

    public function test_the_layout_can_be_turned_off(): void
    {
        config()->set('kinetix.mail.layout', false);

        $this->assertSame('<p>Hello</p>', (new TemplatedMail('Welcome', '<p>Hello</p>'))->render());
    }

    /**
     * The rendered HTML without the `style` attributes the theme is inlined as.
     */
    private function rendered(Mailable $mail): string
    {
        return (string) preg_replace('/ style="[^"]*"/', '', $mail->render());
    }

    public function test_the_html_block_fills_blank_lines(): void
    {
        $this->assertSame("<div>\n<p>a</p>\n<!---->\n<p>b</p>\n</div>", MailLayout::htmlBlock("\n<p>a</p>\n  \n<p>b</p>\n"));
    }
}
