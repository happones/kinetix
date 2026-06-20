<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Illuminate\Database\Eloquent\Model;

class TextInput extends Field
{
    protected string $inputType = 'text';

    protected function getType(): string
    {
        return 'text-input';
    }

    public function password(): static
    {
        $this->inputType = 'password';

        return $this;
    }

    public function email(): static
    {
        $this->inputType = 'email';
        parent::email();

        return $this;
    }

    public function numeric(): static
    {
        $this->inputType = 'number';
        parent::numeric();

        return $this;
    }

    public function url(): static
    {
        $this->inputType = 'url';
        parent::url();

        return $this;
    }

    public function type(string $type): static
    {
        $this->inputType = $type;

        return $this;
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        $data = parent::toData($operation, $record);
        if ($data === null) {
            return null;
        }

        $data->inputType = $this->inputType;

        return $data;
    }
}
