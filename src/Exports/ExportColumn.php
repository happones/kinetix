<?php

declare(strict_types=1);

namespace Happones\Kinetix\Exports;

use BackedEnum;
use Closure;
use Happones\Kinetix\Support\Contracts\HasLabel;
use Happones\Kinetix\Tables\Columns\Summarizers\Summarizer;
use Illuminate\Database\Eloquent\Model;
use UnitEnum;

class ExportColumn
{
    protected string $name;

    protected mixed $label;

    protected ?Closure $formatStateUsing = null;

    /**
     * @var array<int, Summarizer>
     */
    protected array $summarizers = [];

    public function __construct(string $name)
    {
        $this->name  = $name;
        $this->label = (string) str(str_replace('.', ' ', $name))->headline();
    }

    public static function make(string $name): static
    {
        return new static($name);
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    /**
     * Transform the value before it is written to the export cell.
     */
    public function formatStateUsing(Closure $callback): static
    {
        $this->formatStateUsing = $callback;

        return $this;
    }

    /**
     * Add summarizer(s) whose value(s) appear in the export's totals row.
     *
     * @param Summarizer|array<int, Summarizer> $summarizers
     */
    public function summarize(mixed $summarizers): static
    {
        $this->summarizers = is_array($summarizers) ? array_values($summarizers) : [$summarizers];

        return $this;
    }

    /**
     * @return array<int, Summarizer>
     */
    public function getSummarizers(): array
    {
        return $this->summarizers;
    }

    public function hasSummarizers(): bool
    {
        return $this->summarizers !== [];
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getHeading(): string
    {
        return (string) $this->label;
    }

    /**
     * Resolve the cell value for a record (dot-notation aware, enum friendly).
     */
    public function getState(Model $record): mixed
    {
        $value = data_get($record, $this->name);

        if ($value instanceof HasLabel) {
            $value = $value->getLabel();
        } elseif ($value instanceof BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof UnitEnum) {
            $value = $value->name;
        }

        if ($this->formatStateUsing !== null) {
            $value = ($this->formatStateUsing)($value, $record);
        }

        return $value;
    }
}
