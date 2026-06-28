<?php

declare(strict_types=1);

namespace Happones\Kinetix\Mail;

use Illuminate\Support\Facades\Mail;

/**
 * Static entry point for sending editable mail templates:
 *
 *     KinetixMail::send('welcome', $user->email, ['name' => $user->name]);
 *
 * Templates are managed from the <KinetixMailTemplates> UI; your app supplies the
 * variable data at send time. Returns false when the template is missing or
 * disabled, so callers can fall back.
 */
class KinetixMail
{
    public static function template(string $key): ?MailTemplate
    {
        return MailTemplate::query()->where('key', $key)->where('enabled', true)->first();
    }

    /**
     * Render a template to `{subject, html}`, or null if unavailable.
     *
     * @param  array<string, mixed>                      $data
     * @return array{subject: string, html: string}|null
     */
    public static function render(string $key, array $data = []): ?array
    {
        return static::template($key)?->render($data);
    }

    /**
     * Render and send a template to the given recipient(s).
     *
     * @param array<int, string>|string $to
     * @param array<string, mixed>      $data
     */
    public static function send(array|string $to, string $key, array $data = []): bool
    {
        $template = static::template($key);

        if ($template === null) {
            return false;
        }

        $rendered = $template->render($data);
        Mail::to($to)->send(new TemplatedMail($rendered['subject'], $rendered['html']));

        return true;
    }

    /**
     * Send a test using the template's sample data (merged with any overrides).
     *
     * @param array<string, mixed> $data
     */
    public static function test(string $key, string $to, array $data = []): bool
    {
        $template = MailTemplate::query()->where('key', $key)->first();

        if ($template === null) {
            return false;
        }

        $rendered = $template->render(array_merge($template->sampleData(), $data));
        Mail::to($to)->send(new TemplatedMail('[TEST] '.$rendered['subject'], $rendered['html']));

        return true;
    }
}
