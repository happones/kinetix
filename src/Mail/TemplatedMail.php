<?php

declare(strict_types=1);

namespace Happones\Kinetix\Mail;

use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Sends a rendered {@see MailTemplate} (subject + HTML body), inside the app's
 * mail layout unless `kinetix.mail.layout` is off ({@see MailLayout}).
 */
class TemplatedMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $subjectLine,
        public string $bodyHtml,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->subjectLine);
    }

    public function content(): Content
    {
        return MailLayout::content($this->bodyHtml);
    }
}
