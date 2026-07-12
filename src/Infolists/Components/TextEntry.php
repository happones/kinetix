<?php

declare(strict_types=1);

namespace Happones\Kinetix\Infolists\Components;

use Happones\Kinetix\Support\Concerns\FormatsDates;
use Happones\Kinetix\Support\Concerns\FormatsMoney;
use Illuminate\Database\Eloquent\Model;

class TextEntry extends Entry
{
    use FormatsDates;
    use FormatsMoney;

    protected bool $isBadge = false;

    protected bool $isCopyable = false;

    protected bool $isInline = false;

    protected bool $isConfidential = false;

    protected ?int $limit = null;

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
     * UI-only affordance flag (a padlock icon) — the actual masking is
     * always enforced by `ConfidentialCast` on the model attribute itself,
     * regardless of whether an entry declares this.
     */
    public function confidential(bool $condition = true): static
    {
        $this->isConfidential = $condition;

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

    public function getState(?Model $record = null): mixed
    {
        $value = parent::getState($record);

        if ($value === null) {
            return null;
        }

        $value = $this->resolveEnumState($value);

        // Apply date formatting (localized isoFormat or plain PHP format).
        $value = $this->formatDateValue($value);

        // Apply money formatting (localized intl currency).
        $value = $this->formatMoneyValue($value);

        if ($this->limit !== null && is_string($value)) {
            $value = str($value)->limit($this->limit)->toString();
        }

        return $value;
    }

    protected function getExtraData(?Model $record = null): array
    {
        return [
            'isBadge'        => $this->isBadge,
            'isCopyable'     => $this->isCopyable,
            'isInline'       => $this->isInline,
            'isConfidential' => $this->isConfidential,
        ];
    }
}
