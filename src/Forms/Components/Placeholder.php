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
 *
 * Or named like any field, with the label apart:
 *
 *     Placeholder::make('hint')->label(__('Heads up'))->content('…');
 */
class Placeholder extends Component
{
    protected mixed $label;

    protected ?string $name;

    protected mixed $content = null;

    /**
     * @param mixed $label the label shown — or a name, when {@see label()} sets it
     */
    public function __construct(mixed $label)
    {
        $this->label = $label;
        $this->name  = is_string($label) ? $label : null;
    }

    public static function make(mixed $label): static
    {
        return new static($label);
    }

    /**
     * The label shown above the content (a string, a Closure receiving the
     * record, or null for none) — in place of what make() was given.
     */
    public function label(mixed $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function getName(): ?string
    {
        return $this->name;
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
