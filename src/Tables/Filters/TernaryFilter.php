<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Filters;

use Closure;
use Illuminate\Database\Eloquent\Builder;

class TernaryFilter extends Filter
{
    protected ?string $attribute = null;

    protected string $trueLabel = 'Yes';

    protected string $falseLabel = 'No';

    protected ?Closure $trueQuery = null;

    protected ?Closure $falseQuery = null;

    protected function getType(): string
    {
        return 'ternary';
    }

    /**
     * The database column to filter by. Defaults to the filter name.
     */
    public function attribute(string $attribute): static
    {
        $this->attribute = $attribute;

        return $this;
    }

    public function trueLabel(string $label): static
    {
        $this->trueLabel = $label;

        return $this;
    }

    public function falseLabel(string $label): static
    {
        $this->falseLabel = $label;

        return $this;
    }

    /**
     * Override the queries applied for the true / false selections.
     */
    public function queries(Closure $true, Closure $false): static
    {
        $this->trueQuery  = $true;
        $this->falseQuery = $false;

        return $this;
    }

    public function apply(Builder $query, mixed $value): void
    {
        // Blank selection ('') is skipped upstream, so only true/false reach here.
        $isTrue = $value === '1' || $value === 1 || $value === true;

        if ($isTrue && $this->trueQuery !== null) {
            ($this->trueQuery)($query);

            return;
        }

        if (! $isTrue && $this->falseQuery !== null) {
            ($this->falseQuery)($query);

            return;
        }

        $query->where($this->attribute ?? $this->name, $isTrue);
    }

    protected function getExtraData(): array
    {
        return [
            'options' => [
                '1' => $this->trueLabel,
                '0' => $this->falseLabel,
            ],
        ];
    }
}
