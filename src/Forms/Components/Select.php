<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Closure;
use Happones\Kinetix\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Model;

class Select extends Field
{
    /**
     * @var array<string, string>|Closure|string
     */
    protected array|Closure|string $options = [];

    protected function getType(): string
    {
        return 'select';
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
     * Get options list for frontend.
     *
     * @return array<string, string>|null
     */
    protected function getFieldOptions(?Model $record = null): ?array
    {
        if ($this->options instanceof Closure) {
            return ($this->options)($record);
        }

        if (is_string($this->options) && is_subclass_of($this->options, \UnitEnum::class)) {
            $options = [];
            foreach ($this->options::cases() as $case) {
                $label = $case instanceof HasLabel
                    ? $case->getLabel()
                    : ($case instanceof \BackedEnum ? $case->value : $case->name);

                $value                    = $case instanceof \BackedEnum ? $case->value : $case->name;
                $options[(string) $value] = $label;
            }

            return $options;
        }

        return $this->options;
    }
}
