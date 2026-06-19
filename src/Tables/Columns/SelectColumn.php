<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns;

use Closure;

class SelectColumn extends Column
{
    /**
     * @var array<string, string>|Closure
     */
    protected array|Closure $options = [];

    protected function getType(): string
    {
        return 'select-input';
    }

    /**
     * Set the dropdown options.
     *
     * @param array<string, string>|Closure $options
     */
    public function options(array|Closure $options): static
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

        return $this->options;
    }

    public function toArray(): array
    {
        return array_merge(parent::toArray(), [
            'options' => $this->getOptions(),
        ]);
    }
}
