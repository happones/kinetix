<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Illuminate\Database\Eloquent\Model;

class CheckboxList extends Select
{
    protected bool $isInline = false;

    protected bool $isBulkToggleable = false;

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

    /**
     * A "Select all" checkbox above the options: it checks every option in
     * view (the ones a search left, when searchable), or clears them, and
     * shows a dash when only some are checked.
     */
    public function bulkToggleable(bool $condition = true): static
    {
        $this->isBulkToggleable = $condition;

        return $this;
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        $data = parent::toData($operation, $record);
        if ($data === null) {
            return null;
        }

        $data->isInline         = $this->isInline;
        $data->isBulkToggleable = $this->isBulkToggleable;

        return $data;
    }
}
