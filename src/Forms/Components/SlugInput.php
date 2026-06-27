<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Data\FormFieldData;
use Illuminate\Database\Eloquent\Model;

/**
 * A URL-slug text input. When from() points at a sibling field, the slug is
 * generated live from that field's value until the user edits it manually.
 *
 *     SlugInput::make('slug')->from('title');
 *     SlugInput::make('slug')->from('name')->separator('_');
 */
class SlugInput extends Field
{
    protected ?string $from = null;

    protected string $separator = '-';

    protected function getType(): string
    {
        return 'slug-input';
    }

    public function from(string $field): static
    {
        $this->from = $field;

        return $this;
    }

    public function separator(string $separator): static
    {
        $this->separator = $separator;

        return $this;
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        $data = parent::toData($operation, $record);

        if ($data === null) {
            return null;
        }

        $data->slugConfig = [
            'from'      => $this->from,
            'separator' => $this->separator,
        ];

        return $data;
    }
}
