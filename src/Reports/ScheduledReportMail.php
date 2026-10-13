<?php

declare(strict_types=1);

namespace Happones\Kinetix\Reports;

use Happones\Kinetix\Mail\MailLayout;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Attachment;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Delivers a scheduled report's generated file as an email attachment, with a
 * short note inside the app's mail layout ({@see MailLayout}).
 */
class ScheduledReportMail extends Mailable
{
    use Queueable;
    use SerializesModels;

    public function __construct(
        public string $reportSubject,
        public string $attachmentPath,
        public string $attachmentName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->reportSubject);
    }

    public function content(): Content
    {
        $app   = (string) config('app.name', 'Application');
        $intro = e((string) __('kinetix.report_mail_intro', ['name' => $this->reportSubject]));
        $outro = e((string) __('kinetix.report_mail_outro', ['app' => $app]));

        return MailLayout::content("<p>{$intro}</p><p style=\"color:#6b7280;font-size:13px\">{$outro}</p>");
    }

    /**
     * @return array<int, Attachment>
     */
    public function attachments(): array
    {
        return [
            Attachment::fromPath($this->attachmentPath)->as($this->attachmentName),
        ];
    }
}
