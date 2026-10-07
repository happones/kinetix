<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Filters;

use Happones\Kinetix\Forms\Components\Field;
use Happones\Kinetix\Forms\Form;
use Illuminate\Database\Eloquent\Builder;

/**
 * A composite filter rendered as a FORM of several fields, whose combined
 * values are handed to the query callback as an array — the Filament
 * `Filter::make()->form([...])->query(fn ($query, array $data) => …)` pattern.
 *
 * Unlike the single-value filters (Select, DateRange, …), this lets one filter
 * gather multiple inputs — a price floor + ceiling, a date window + a status —
 * and apply them together:
 *
 *     FormFilter::make('price')
 *         ->schema([
 *             NumberField::make('from')->label('From'),
 *             NumberField::make('to')->label('To'),
 *         ])
 *         ->query(function (Builder $query, array $data) {
 *             $query
 *                 ->when($data['from'] ?? null, fn ($q, $v) => $q->where('price', '>=', $v))
 *                 ->when($data['to'] ?? null, fn ($q, $v) => $q->where('price', '<=', $v));
 *         });
 *
 * The schema reuses the same form field components as {@see Form}
 * (so every input type, and conditional fields, are available), and the
 * frontend renders it with the shared `KinetixFormSchema`.
 */
class FormFilter extends Filter
{
    /**
     * @var array<int, Field>
     */
    protected array $schema = [];

    protected function getType(): string
    {
        return 'form';
    }

    /**
     * Declare the filter's form fields.
     *
     * @param array<int, Field> $schema
     */
    public function schema(array $schema): static
    {
        $this->schema = array_values($schema);

        return $this;
    }

    /**
     * Apply the filter. `$value` is the form's values array; an empty or
     * non-array value is a no-op (nothing selected), so the base filter's
     * null/'' guard is complemented here for the array case.
     */
    public function apply(Builder $query, mixed $value): void
    {
        if (! is_array($value) || $value === []) {
            return;
        }

        // Drop blank sub-values so a callback's `$data['x'] ?? null` reads null
        // for an untouched field rather than an empty string.
        $data = array_filter($value, static fn (mixed $v): bool => $v !== null && $v !== '' && $v !== []);

        if ($data === []) {
            return;
        }

        parent::apply($query, $data);
    }

    /**
     * @return array<string, mixed>
     */
    protected function getExtraData(): array
    {
        return [
            'schema' => array_map(
                static fn (Field $field): array => $field->toData('create')?->toArray() ?? [],
                $this->schema,
            ),
        ];
    }
}
