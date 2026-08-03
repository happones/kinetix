<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Filters;

use Illuminate\Database\Eloquent\Builder;

/**
 * Soft-delete scope filter. Blank = active records only (default scope),
 * 'with' = include trashed, 'only' = only trashed. Requires a SoftDeletes model.
 */
class TrashedFilter extends Filter
{
    public function __construct(string $name = 'trashed')
    {
        parent::__construct($name);
        $this->label((string) __('kinetix.trashed_filter'));
    }

    public static function make(string $name = 'trashed'): static
    {
        return new static($name);
    }

    protected function getType(): string
    {
        return 'select';
    }

    public function apply(Builder $query, mixed $value): void
    {
        if ($value === 'with') {
            $query->withTrashed();

            return;
        }

        if ($value === 'only') {
            $query->onlyTrashed();
        }

        // Blank/anything else: the SoftDeletes global scope already hides trashed rows.
    }

    protected function getExtraData(): array
    {
        return [
            'options' => [
                'with' => (string) __('kinetix.with_trashed'),
                'only' => (string) __('kinetix.only_trashed'),
            ],
        ];
    }
}
