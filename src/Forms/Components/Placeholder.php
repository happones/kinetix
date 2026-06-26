<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Closure;
use Happones\Kinetix\Data\FormFieldData;
use Illuminate\Database\Eloquent\Model;

/**
 * A read-only display component: a label and arbitrary content shown inside the
 * form layout. Holds no field state and is never validated or dehydrated.
 *
 *     Placeholder::make('Account ID')->content(fn ($record) => $record?->id);
 */
class Placeholder extends Component
{
    protected mixed $label;

    protected mixed $content = null;

    public function __construct(mixed $label)
    {
        $this->label = $label;
    }

    public static function make(mixed $label): static
    {
        return new static($label);
    }

    public function content(mixed $content): static
    {
        $this->content = $content;

        return $this;
    }

    protected function getType(): string
    {
        return 'placeholder';
    }

    public function toData(string $operation, ?Model $record = null): ?FormFieldData
    {
        if ($this->isHidden($operation, $record)) {
            return null;
        }

        $label   = $this->label instanceof Closure ? ($this->label)($record) : $this->label;
        $content = $this->content instanceof Closure ? ($this->content)($record) : $this->content;

        return new FormFieldData(
            type: $this->getType(),
            label: $label !== null ? (string) $label : null,
            columnSpan: $this->columnSpan,
            content: $content !== null ? (string) $content : null,
        );
    }
}
