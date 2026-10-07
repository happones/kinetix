<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns;

class TextInputColumn extends Column
{
    protected string $inputType = 'text';

    protected ?string $placeholder = null;

    protected function getType(): string
    {
        return 'text-input';
    }

    public function type(string $inputType): static
    {
        $this->inputType = $inputType;

        return $this;
    }

    public function placeholder(string $placeholder): static
    {
        $this->placeholder = $placeholder;

        return $this;
    }

    protected function getExtraData(): array
    {
        return [
            'inputType'   => $this->inputType,
            'placeholder' => $this->placeholder,
        ];
    }

    public function isEditable(): bool
    {
        return true;
    }

    /**
     * Derive a baseline rule from the HTML input type the cell renders, so an
     * `email`/`url`/`number` cell can't be saved with a value its own control
     * would reject. Layer stricter rules with {@see rules()}.
     *
     * @return array<int, mixed>
     */
    protected function getTypeRules(): array
    {
        return match ($this->inputType) {
            'email'  => ['nullable', 'email'],
            'url'    => ['nullable', 'url'],
            'number' => ['nullable', 'numeric'],
            default  => ['nullable', 'string'],
        };
    }
}
