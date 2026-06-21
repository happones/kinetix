<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Illuminate\Database\Eloquent\Model;

class CheckboxList extends Select
{
    protected bool $isInline = false;

    protected function getType(): string
    {
        return 'checkbox-list';
    }

    /**
     * Lay the checkboxes out horizontally instead of stacked.
     */
    public function inline(bool $condition = true): static
    {
        $this->isInline = $condition;

        return $this;
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        $data = parent::toData($operation, $record);
        if ($data === null) {
            return null;
        }

        $data->isInline = $this->isInline;

        return $data;
    }
}
