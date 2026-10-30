<?php

declare(strict_types=1);

namespace Happones\Kinetix\Settings;

use Happones\Kinetix\Forms\Components\Component;
use Happones\Kinetix\Forms\Form;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * A database-backed settings panel. Subclass it and return Kinetix Form
 * components from {@see schema()}; the Forms engine then handles validation,
 * defaults and serialization for free. Each field is persisted under
 * `{group}.{field}` (e.g. `general.site_name`) so values are addressable via
 * `KinetixSettings::get('general.site_name')`.
 */
abstract class SettingsPage
{
    public static function make(): static
    {
        return new static;
    }

    /**
     * The settings form fields.
     *
     * @return array<int, Component>
     */
    abstract public function schema(): array;

    /**
     * The key prefix every field on this page is stored under. Defaults to the
     * kebab-cased class basename without the `SettingsPage` suffix.
     */
    public function group(): string
    {
        return (string) str(class_basename(static::class))->beforeLast('SettingsPage')->kebab();
    }

    /**
     * The route key for this page (defaults to the group).
     */
    public function key(): string
    {
        return $this->group();
    }

    public function title(): string
    {
        return (string) str(class_basename(static::class))->beforeLast('SettingsPage')->headline();
    }

    public function navigationIcon(): string
    {
        return 'settings';
    }

    /**
     * The ability that opens and saves this page — several mean any of them.
     * A page for someone other than the platform's settings admins (a team
     * owner's own configuration) names its own ability here.
     *
     * @return string|array<int, string>
     */
    public function ability(): string|array
    {
        return 'settings.manage';
    }

    /**
     * Whether the user may open and save this page. Defaults to
     * {@see ability()}; override for a rule a Gate ability can't express.
     */
    public function authorize(?Authenticatable $user): bool
    {
        return $user !== null && Gate::forUser($user)->any((array) $this->ability());
    }

    /**
     * Field names whose values should be stored encrypted (e.g. API keys).
     *
     * @return array<int, string>
     */
    public function encrypted(): array
    {
        return [];
    }

    public function form(): Form
    {
        return Form::make()->schema($this->schema())->operation('edit');
    }

    /**
     * The current value of each field on this page: in a team, its own value
     * else the global one it inherits. Pass `$settings` to read another scope
     * (`KinetixSettings::global()`, `KinetixSettings::forTeam($team)`).
     *
     * @return array<string, mixed>
     */
    public function currentValues(?SettingsManager $settings = null): array
    {
        $settings ??= KinetixSettings::manager();
        $values = [];

        foreach (array_keys($this->form()->getFields()) as $name) {
            $values[$name] = $settings->get($this->prefixed($name));
        }

        return $values;
    }

    /**
     * Validate the input through the form and persist each field under its
     * prefixed key. Returns the dehydrated state that was saved.
     *
     * In a team, a value equal to the global one it inherits is not stored,
     * and a stored one set back to it is forgotten: the team keeps following
     * the global value until it really changes it.
     *
     * @param  array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(array $input, ?SettingsManager $settings = null): array
    {
        $settings ??= KinetixSettings::manager();

        $form = $this->form()->fill($this->currentValues($settings));

        $form->validate($input);
        $state = $form->getState($input);

        $encrypted = $this->encrypted();
        $inherited = $settings->inherited();
        $own       = $settings->own();

        foreach ($state as $name => $value) {
            $key = $this->prefixed($name);

            if (array_key_exists($key, $inherited) && $inherited[$key] === $value) {
                if (array_key_exists($key, $own)) {
                    $settings->forget($key);
                }

                continue;
            }

            $settings->set($key, $value, in_array($name, $encrypted, true));
        }

        return $state;
    }

    protected function prefixed(string $name): string
    {
        return "{$this->group()}.{$name}";
    }

    /**
     * Serialize the page (metadata + the form filled with current values).
     *
     * @return array{key: string, title: string, icon: string, form: array<string, mixed>}
     */
    public function toArray(): array
    {
        return [
            'key'   => $this->key(),
            'title' => $this->title(),
            'icon'  => $this->navigationIcon(),
            'form'  => $this->form()->fill($this->currentValues())->toArray(),
        ];
    }
}
