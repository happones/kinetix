<?php

declare(strict_types=1);

namespace Happones\Kinetix\Settings;

use Happones\Kinetix\Forms\Components\Component;
use Happones\Kinetix\Forms\Form;

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
     * The current stored value for each field on this page.
     *
     * @return array<string, mixed>
     */
    public function currentValues(): array
    {
        $values = [];

        foreach (array_keys($this->form()->getFields()) as $name) {
            $values[$name] = KinetixSettings::get($this->prefixed($name));
        }

        return $values;
    }

    /**
     * Validate the input through the form and persist each field under its
     * prefixed key. Returns the dehydrated state that was saved.
     *
     * @param  array<string, mixed> $input
     * @return array<string, mixed>
     */
    public function save(array $input): array
    {
        $form = $this->form()->fill($this->currentValues());

        $form->validate($input);
        $state = $form->getState($input);

        $encrypted = $this->encrypted();

        foreach ($state as $name => $value) {
            KinetixSettings::set($this->prefixed($name), $value, in_array($name, $encrypted, true));
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
