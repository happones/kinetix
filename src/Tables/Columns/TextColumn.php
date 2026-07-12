<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns;

use Closure;
use Happones\Kinetix\Support\Concerns\FormatsDates;
use Happones\Kinetix\Support\Concerns\FormatsMoney;
use Happones\Kinetix\Support\Contracts\HasColor;
use Happones\Kinetix\Support\Contracts\HasLabel;
use Illuminate\Database\Eloquent\Model;

class TextColumn extends Column
{
    use FormatsDates;
    use FormatsMoney;

    protected bool $isBadge = false;

    protected bool $isConfidential = false;

    protected string|Closure|null $badgeColor = 'gray';

    protected ?int $limit = null;

    protected string|Closure|null $description = null;

    protected string $descriptionPosition = 'below'; // below, above

    protected function getType(): string
    {
        return 'text';
    }

    public function badge(bool $condition = true): static
    {
        $this->isBadge = $condition;

        return $this;
    }

    /**
     * UI-only affordance flag (a padlock icon) — the actual masking is
     * always enforced by `ConfidentialCast` on the model attribute itself,
     * regardless of whether a column declares this.
     */
    public function confidential(bool $condition = true): static
    {
        $this->isConfidential = $condition;

        return $this;
    }

    public function badgeColor(string|Closure $color): static
    {
        $this->badgeColor = $color;

        return $this;
    }

    public function limit(int $limit): static
    {
        $this->limit = $limit;

        return $this;
    }

    public function description(string|Closure $description, string $position = 'below'): static
    {
        $this->description         = $description;
        $this->descriptionPosition = $position;

        return $this;
    }

    public function getState(Model $record): mixed
    {
        $value = parent::getState($record);

        if ($value === null) {
            return null;
        }

        // Auto-resolve Enums to their labels
        if ($value instanceof HasLabel || (is_object($value) && method_exists($value, 'getLabel'))) {
            $value = $value->getLabel();
        } elseif ($value instanceof \BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof \UnitEnum) {
            $value = $value->name;
        }

        // Apply date formatting (localized isoFormat or plain PHP format).
        $value = $this->formatDateValue($value);

        // Apply money formatting (localized intl currency).
        $value = $this->formatMoneyValue($value);

        // Apply string limits
        if ($this->limit !== null && is_string($value)) {
            $value = str($value)->limit($this->limit)->toString();
        }

        return $value;
    }

    /**
     * Get the description text computed for a given record.
     */
    public function getDescription(Model $record): ?string
    {
        if ($this->description === null) {
            return null;
        }

        if ($this->description instanceof Closure) {
            return ($this->description)($record);
        }

        return $this->description;
    }

    /**
     * Get the badge color resolved for a given record.
     */
    public function getBadgeColor(Model $record): string
    {
        $rawState = parent::getState($record);

        if ($rawState instanceof HasColor || (is_object($rawState) && method_exists($rawState, 'getColor'))) {
            return $rawState->getColor() ?? 'gray';
        }

        if ($this->badgeColor instanceof Closure) {
            return ($this->badgeColor)($this->getState($record), $record);
        }

        return $this->badgeColor ?? 'gray';
    }

    protected function getExtraData(): array
    {
        return [
            'isBadge'             => $this->isBadge,
            'descriptionPosition' => $this->descriptionPosition,
            'isConfidential'      => $this->isConfidential,
        ];
    }
}
