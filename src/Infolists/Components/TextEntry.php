<?php

declare(strict_types=1);

namespace Happones\Kinetix\Infolists\Components;

use Happones\Kinetix\Support\Concerns\FormatsDates;
use Illuminate\Database\Eloquent\Model;

class TextEntry extends Entry
{
    use FormatsDates;

    protected bool $isBadge = false;

    protected bool $isCopyable = false;

    protected bool $isInline = false;

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

        // Apply date formatting (localized isoFormat or plain PHP format).
        $value = $this->formatDateValue($value);

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
