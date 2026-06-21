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
}
