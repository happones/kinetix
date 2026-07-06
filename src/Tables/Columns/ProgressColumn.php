<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns;

use Closure;
use Illuminate\Database\Eloquent\Model;

class ProgressColumn extends Column
{
    protected string|Closure|null $color = 'primary';

    protected int|float|string|Closure|null $progress = null;

    protected int|float|string|Closure|null $maxValue = null;

    protected function getType(): string
    {
        return 'progress';
    }

    public function color(string|Closure $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function progress(int|float|string|Closure $progress): static
    {
        $this->progress = $progress;

        return $this;
    }

    public function maxValue(int|float|string|Closure $maxValue): static
    {
        $this->maxValue = $maxValue;

        return $this;
    }

    public function getRawState(Model $record): mixed
    {
        return data_get($record, $this->name);
    }

    public function getProgress(Model $record): ?float
    {
        $percent  = null;
        $rawValue = $this->getRawState($record);

        if ($this->progress !== null) {
            if ($this->progress instanceof Closure) {
                $percent = ($this->progress)($rawValue, $record);
            } elseif (is_numeric($this->progress)) {
                $percent = (float) $this->progress;
            } else {
                $percent = (float) data_get($record, $this->progress);
            }
        } elseif ($this->maxValue !== null) {
            $max = null;
            if ($this->maxValue instanceof Closure) {
                $max = ($this->maxValue)($record);
            } elseif (is_numeric($this->maxValue)) {
                $max = (float) $this->maxValue;
            } else {
                $max = (float) data_get($record, $this->maxValue);
            }

            if ($max !== null && $max > 0) {
                $percent = ((float) $rawValue / $max) * 100;
            }
        } else {
            $percent = is_numeric($rawValue) ? (float) $rawValue : null;
        }

        if ($percent !== null) {
            return min(100.0, max(0.0, (float) $percent));
        }

        return null;
    }

    public function getProgressColor(Model $record): ?string
    {
        if ($this->color instanceof Closure) {
            return ($this->color)($this->getRawState($record), $record);
        }

        return $this->color;
    }
}
