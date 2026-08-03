<?php

declare(strict_types=1);

namespace Happones\Kinetix\Mail;

use Happones\Kinetix\Support\KinetixTeams;
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
        $template = static::resolve($key);

        // A team that disabled its own override has turned the mail off for
        // itself — it does not silently fall back to the global default.
        return $template !== null && $template->enabled ? $template : null;
    }

    /**
     * The template for a key in the current tenant: the team's own override when
     * it has one, else the global (`team_id` NULL) default.
     *
     * Resolution runs off the request, so a mail sent from a **queued job** —
     * where there is no team context — falls back to the global template. Pass
     * the team explicitly when a job must render a specific tenant's override.
     */
    public static function resolve(string $key, int|string|null $teamId = null): ?MailTemplate
    {
        $query = MailTemplate::query()->where('key', $key);

        if (! KinetixTeams::enabledFor('mail_templates')) {
            return $query->first();
        }

        $teamId ??= KinetixTeams::currentTeamKey();

        // Order puts the team's override ahead of the global default; both are
        // fetched in one query.
        return $query
            ->where(fn ($inner) => $inner->where('team_id', $teamId)->orWhereNull('team_id'))
            ->orderByRaw('CASE WHEN team_id IS NULL THEN 1 ELSE 0 END')
            ->first();
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
        $template = static::resolve($key);

        if ($template === null) {
            return false;
        }

        $rendered = $template->render(array_merge($template->sampleData(), $data));
        Mail::to($to)->send(new TemplatedMail('[TEST] '.$rendered['subject'], $rendered['html']));

        return true;
    }
}
