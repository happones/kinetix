<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Columns;

use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;

class TextColumn extends Column
{
    protected bool $isBadge = false;

    protected string|Closure|null $badgeColor = 'gray';

    protected ?string $dateFormat = null;

    protected ?int $limit = null;

    protected string|Closure|null $description = null;

    protected string $descriptionPosition = 'below'; // below, above

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

    public function badgeColor(string|Closure $color): static
    {
        $this->badgeColor = $color;

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

    public function description(string|Closure $description, string $position = 'below'): static
    {
        $this->description = $description;
        $this->descriptionPosition = $position;

        return $this;
    }

    public function money(string $currency = 'USD'): static
    {
        $this->currencyCode = $currency;

        return $this;
    }

    public function getState(Model $record): mixed
    {
        $value = parent::getState($record);

        if ($value === null) {
            return null;
        }

        // Auto-resolve Enums to their labels
        if ($value instanceof \Happones\Kinetix\Support\Contracts\HasLabel) {
            $value = $value->getLabel();
        } elseif ($value instanceof \BackedEnum) {
            $value = $value->value;
        } elseif ($value instanceof \UnitEnum) {
            $value = $value->name;
        }

        // Apply date formatting
        if ($this->dateFormat !== null) {
            if ($value instanceof CarbonInterface) {
                $value = $value->format($this->dateFormat);
            } elseif (is_string($value)) {
                $value = \Illuminate\Support\Carbon::parse($value)->format($this->dateFormat);
            }
        }

        // Apply money formatting
        if ($this->currencyCode !== null) {
            $value = '$' . number_format((float) $value, 2) . ' ' . $this->currencyCode;
        }

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

        if ($rawState instanceof \Happones\Kinetix\Support\Contracts\HasColor) {
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
        ];
    }
}
