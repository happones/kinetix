<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns;

use Closure;
use Happones\Kinetix\Support\Contracts\HasLabel;
use Illuminate\Validation\Rule;

class SelectColumn extends Column
{
    /**
     * @var array<string, string>|Closure|class-string
     */
    protected array|Closure|string $options = [];

    protected function getType(): string
    {
        return 'select-input';
    }

    /**
     * Set the dropdown options.
     *
     * @param array<string, string>|Closure|string $options
     */
    public function options(array|Closure|string $options): static
    {
        $this->options = $options;

        return $this;
    }

    /**
     * Get the options list.
     *
     * @return array<string, string>
     */
    public function getOptions(): array
    {
        if ($this->options instanceof Closure) {
            return ($this->options)();
        }

        if (is_string($this->options) && is_subclass_of($this->options, \UnitEnum::class)) {
            $options = [];
            foreach ($this->options::cases() as $case) {
                $label = ($case instanceof HasLabel || method_exists($case, 'getLabel'))
                    ? $case->getLabel()
                    : ($case instanceof \BackedEnum ? $case->value : $case->name);

                $value                    = $case instanceof \BackedEnum ? $case->value : $case->name;
                $options[(string) $value] = $label;
            }

            return $options;
        }

        return is_array($this->options) ? $this->options : [];
    }

    protected function getExtraData(): array
    {
        return [
            'options' => $this->getOptions(),
        ];
    }

    public function isEditable(): bool
    {
        return true;
    }

    /**
     * A select can only ever submit one of its declared option KEYS. The
     * rule is derived from the same `getOptions()` the control renders, so a
     * value for a removed/forbidden option is rejected server-side.
     *
     * @return array<int, mixed>
     */
    protected function getTypeRules(): array
    {
        $keys = array_map('strval', array_keys($this->getOptions()));

        if ($keys === []) {
            return [];
        }

        return [Rule::in($keys)];
    }
}
