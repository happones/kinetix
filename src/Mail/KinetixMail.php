<?php

declare(strict_types=1);

namespace Happones\Kinetix\Mail;

use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Mail;

/**
 * Static entry point for sending editable mail templates:
 *
 *     KinetixMail::send($user->email, 'welcome', ['name' => $user->name]);
 *     KinetixMail::sendFor($order->team_id, $to, 'receipt', [...]); // outside a team's request
 *
 * Templates are managed from the <KinetixMailTemplates> UI; your app supplies the
 * variable data at send time. Returns false when the template is missing or
 * disabled, so callers can fall back.
 */
class KinetixMail
{
    /**
     * The current team's enabled template for the key: its own, else the
     * global default.
     */
    public static function template(string $key): ?MailTemplate
    {
        return static::templateFor(KinetixTeams::currentTeamKey(), $key);
    }

    /**
     * One team's enabled template for the key (`null`: the global default
     * alone), whatever team the request serves.
     */
    public static function templateFor(Model|int|string|null $team, string $key): ?MailTemplate
    {
        $template = static::resolveIn($key, $team instanceof Model ? $team->getKey() : $team);

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
     * the team explicitly when a job must render a specific tenant's override,
     * or send with {@see sendFor()}.
     */
    public static function resolve(string $key, int|string|null $teamId = null): ?MailTemplate
    {
        return static::resolveIn($key, $teamId ?? KinetixTeams::currentTeamKey());
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
     * One team's template rendered as a Mailable, e.g. for a notification's
     * `toMail()`; null when it is missing or disabled.
     *
     * @param array<string, mixed> $data
     */
    public static function mailableFor(Model|int|string|null $team, string $key, array $data = []): ?TemplatedMail
    {
        $rendered = static::templateFor($team, $key)?->render($data);

        return $rendered === null ? null : new TemplatedMail($rendered['subject'], $rendered['html']);
    }

    /**
     * Render and send a template to the given recipient(s).
     *
     * @param array<int, string>|string $to
     * @param array<string, mixed>      $data
     */
    public static function send(array|string $to, string $key, array $data = []): bool
    {
        return static::sendFor(KinetixTeams::currentTeamKey(), $to, $key, $data);
    }

    /**
     * Send one team's template (`null`: the global default) whatever team the
     * request serves: a queued job, a console command, a model event.
     *
     * @param array<int, string>|string $to
     * @param array<string, mixed>      $data
     */
    public static function sendFor(Model|int|string|null $team, array|string $to, string $key, array $data = []): bool
    {
        $mail = static::mailableFor($team, $key, $data);

        if ($mail === null) {
            return false;
        }

        Mail::to($to)->send($mail);

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

    protected static function resolveIn(string $key, int|string|null $teamId): ?MailTemplate
    {
        $query = MailTemplate::query()->where('key', $key);

        if (! KinetixTeams::enabledFor('mail_templates')) {
            return $query->first();
        }

        // Order puts the team's override ahead of the global default; both are
        // fetched in one query.
        return $query
            ->where(fn ($inner) => $inner->where('team_id', $teamId)->orWhereNull('team_id'))
            ->orderByRaw('CASE WHEN team_id IS NULL THEN 1 ELSE 0 END')
            ->first();
    }
}
