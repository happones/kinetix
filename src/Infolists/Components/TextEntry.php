<?php

declare(strict_types=1);

namespace Happones\Kinetix\Infolists\Components;

use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

class TextEntry extends Entry
{
    protected bool $isBadge = false;

    protected bool $isCopyable = false;

    protected bool $isInline = false;

    protected ?string $dateFormat = null;

    protected ?int $limit = null;

    protected ?string $currencyCode = null;

    protected function getType(): string
    {
        return 'text';
    }

    public function badge(bool $condition = true): static
    {
        $this->isBadge = $condition;

        return $this;
    }

    public function copyable(bool $condition = true): static
    {
        $this->isCopyable = $condition;

        return $this;
    }

    /**
     * Render the entry value inline with its label instead of stacked below it.
     */
    public function inlineLabel(bool $condition = true): static
    {
        $this->isInline = $condition;

        return $this;
    }

    public function date(string $format = 'M j, Y'): static
    {
        $this->dateFormat = $format;

        return $this;
    }

    public function dateTime(string $format = 'M j, Y g:i a'): static
    {
        $this->dateFormat = $format;

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    public function money(string $currency = 'USD'): static
    {
        $this->currencyCode = $currency;

        return $this;
    }

    public function getState(?Model $record = null): mixed
    {
        $value = parent::getState($record);

        if ($value === null) {
            return null;
        }

        $value = $this->resolveEnumState($value);

        if ($this->dateFormat !== null) {
            if ($value instanceof CarbonInterface) {
                $value = $value->format($this->dateFormat);
            } elseif (is_string($value)) {
                $value = Carbon::parse($value)->format($this->dateFormat);
            }
        }

        if ($this->currencyCode !== null) {
            $value = '$'.number_format((float) $value, 2).' '.$this->currencyCode;
        }

        if ($this->limit !== null && is_string($value)) {
            $value = str($value)->limit($this->limit)->toString();
        }

        return $value;
    }

    protected function getExtraData(?Model $record = null): array
    {
        return [
            'isBadge'    => $this->isBadge,
            'isCopyable' => $this->isCopyable,
            'isInline'   => $this->isInline,
        ];
    }
}
