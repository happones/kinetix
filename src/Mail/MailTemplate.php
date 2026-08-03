<?php

declare(strict_types=1);

namespace Happones\Kinetix\Mail;

use Happones\Kinetix\Support\Concerns\ScopedToTeam;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * An editable email template — a subject + Markdown/HTML body with `{{ var }}`
 * placeholders. `render($data)` interpolates the variables (HTML-escaped) and
 * compiles Markdown bodies to HTML.
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

    protected $table = 'kinetix_mail_templates';

    protected $guarded = [];

    protected $casts = [
        'variables' => 'array',
        'enabled'   => 'boolean',
    ];

    /**
     * Render the template with the given data into a subject + HTML body.
     *
     * @param  array<string, mixed>                 $data
     * @return array{subject: string, html: string}
     */
    public function render(array $data = []): array
    {
        $subject = static::interpolate($this->subject, $data, escape: false);
        $body    = static::interpolate($this->body, $data, escape: $this->format === 'markdown');

        $html = $this->format === 'markdown'
            ? Str::markdown($body)
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
            $key = $variable['key'] ?? null;
            if (is_string($key) && $key !== '') {
                $data[$key] = $variable['sample'] ?? $key;
            }
        }

        return $data;
    }

    /**
     * Replace `{{ key }}` placeholders with values from $data.
     *
     * @param array<string, mixed> $data
     */
    public static function interpolate(string $template, array $data, bool $escape = true): string
    {
        return (string) preg_replace_callback(
            '/\{\{\s*([a-zA-Z0-9_.]+)\s*\}\}/',
            static function (array $m) use ($data, $escape): string {
                $value = (string) ($data[$m[1]] ?? '');

                return $escape ? e($value) : $value;
            },
            $template,
        );
    }
}
