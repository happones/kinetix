<?php

declare(strict_types=1);

namespace Happones\Kinetix\Mail;

use Happones\Kinetix\Support\Concerns\ScopedToTeam;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An editable email template — a subject + Markdown/HTML body with `{{ var }}`
 * placeholders (escaped) and `{!! var !!}` placeholders (raw). `render($data)`
 * interpolates the variables and compiles Markdown bodies to HTML.
 *
 * @property int|string|null                       $team_id
 * @property string                                $key
 * @property string                                $name
 * @property string                                $subject
 * @property string                                $body
 * @property string                                $format
 * @property array<int, array<string, mixed>>|null $variables
 * @property bool                                  $enabled
 */
class MailTemplate extends Model
{
    use ScopedToTeam;

    public static function kinetixTeamModule(): string
    {
        return 'mail_templates';
    }

    public static function kinetixTeamRowsIncludeGlobal(): bool
    {
        return true;
    }

    protected $table = 'kinetix_mail_templates';

    protected $guarded = [];

    protected $casts = [
        'enabled' => 'boolean',
    ];

    /**
     * The declared variables, always as `{key, label, sample}` rows. A bare
     * list of names (`['team', 'count']`), the shape a hand-written seeder or
     * import reaches for first, left the editor with empty rows and every
     * sample blank; it reads — and is stored — as rows named by those keys.
     *
     * @return Attribute<array<int, array{key: string, label: ?string, sample: ?string}>|null, mixed>
     */
    protected function variables(): Attribute
    {
        return Attribute::make(
            get: fn (mixed $value): ?array => $value === null
                ? null
                : self::variableRows(is_string($value) ? (json_decode($value, true) ?: []) : (array) $value),
            set: fn (mixed $value): ?string => $value === null
                ? null
                : (string) json_encode(self::variableRows((array) $value)),
        );
    }

    /**
     * @param  array<int|string, mixed>                                        $variables
     * @return array<int, array{key: string, label: ?string, sample: ?string}>
     */
    private static function variableRows(array $variables): array
    {
        $rows = [];

        foreach ($variables as $variable) {
            $variable = is_string($variable) ? ['key' => $variable] : (array) $variable;
            $key      = $variable['key'] ?? null;

            if (! is_string($key) || $key === '') {
                continue;
            }

            $rows[] = [
                'key'    => $key,
                'label'  => isset($variable['label']) ? (string) $variable['label'] : null,
                'sample' => isset($variable['sample']) ? (string) $variable['sample'] : null,
            ];
        }

        return $rows;
    }

    /**
     * Render the template with the given data into a subject + HTML body.
     * Body variables are escaped for the body's format (`{!! key !!}` opts
     * one out); the subject is plain text. Markdown bodies may carry raw
     * HTML, but never an unsafe (`javascript:`…) link.
     *
     * @param  array<string, mixed>                 $data
     * @return array{subject: string, html: string}
     */
    public function render(array $data = []): array
    {
        $markdown = $this->format === 'markdown';
        $subject  = static::interpolate($this->subject, $data, escape: false);
        $body     = static::interpolate($this->body, $data, escape: true, format: $markdown ? 'markdown' : 'html');

        $html = $markdown
            ? Str::markdown($body, ['allow_unsafe_links' => false])
            : $body;

        return [
            'subject' => strip_tags($subject),
            'html'    => $html,
        ];
    }

    /**
     * Sample data drawn from the declared variables (for previews/tests).
     *
     * @return array<string, mixed>
     */
    public function sampleData(): array
    {
        $data = [];
        foreach ($this->variables ?? [] as $variable) {
            $data[$variable['key']] = $variable['sample'] ?? $variable['key'];
        }

        return $data;
    }

    /**
     * Replace `{{ key }}` placeholders with values from $data, escaped for
     * the template's format when `$escape` is on: HTML-escaped for `html`,
     * every ASCII punctuation character backslash-escaped for `markdown` (so
     * a value is shown as text, never read as Markdown or HTML). A
     * `{!! key !!}` placeholder inserts its value as-is.
     *
     * @param array<string, mixed> $data
     */
    public static function interpolate(string $template, array $data, bool $escape = true, string $format = 'html'): string
    {
        return (string) preg_replace_callback(
            '/\{!!\s*([a-zA-Z0-9_.]+)\s*!!\}|\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            static function (array $m) use ($data, $escape, $format): string {
                $raw   = ($m[2] ?? '') === '';
                $value = (string) ($data[$raw ? $m[1] : $m[2]] ?? '');

                if ($raw || ! $escape) {
                    return $value;
                }

                return $format === 'markdown' ? self::escapeMarkdown($value) : e($value);
            },
            $template,
        );
    }

    /**
     * Backslash-escape every ASCII punctuation character, which CommonMark
     * then renders as that literal character.
     */
    protected static function escapeMarkdown(string $value): string
    {
        return (string) preg_replace('/([!-\/:-@\[-`{-~])/', '\\\\$1', $value);
    }
}
