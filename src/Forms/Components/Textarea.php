<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Illuminate\Database\Eloquent\Model;

class Textarea extends Field
{
    protected ?int $rows = null;

    protected function getType(): string
    {
        return 'textarea';
    }

    public function rows(int $rows): static
    {
        $this->rows = $rows;

        return $this;
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        $data = parent::toData($operation, $record);
        if ($data === null) {
            return null;
        }

        if ($this->rows !== null) {
            $data->extraInputAttributes = array_merge($data->extraInputAttributes ?? [], [
                'rows' => (string) $this->rows,
            ]);
        }

        return $data;
    }
}
