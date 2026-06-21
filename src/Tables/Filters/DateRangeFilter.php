<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tables\Filters;

use Illuminate\Database\Eloquent\Builder;

class DateRangeFilter extends Filter
{
    protected ?string $attribute = null;

    protected bool $useCalendar = false;

    protected int $numberOfMonths = 1;

    protected ?string $locale = null;

    protected ?string $weekdayFormat = null;

    protected bool $fixedWeeks = false;

    protected ?string $minValue = null;

    protected ?string $maxValue = null;

    protected function getType(): string
    {
        return 'date-range';
    }

    /**
     * The date column to filter by. Defaults to the filter name.
     */
    public function attribute(string $attribute): static
    {
        $this->attribute = $attribute;

        return $this;
    }

    /**
     * Render the shadcn-style range calendar (Reka UI) instead of two native
     * date inputs. Requires reka-ui + @internationalized/date in the host app.
     */
    public function calendar(bool $condition = true): static
    {
        $this->useCalendar = $condition;

        return $this;
    }

    /**
     * Number of month grids shown side by side in the calendar variant.
     */
    public function months(int $count): static
    {
        $this->numberOfMonths = max(1, $count);
        $this->useCalendar = true;

        return $this;
    }

    /**
     * BCP-47 locale for the calendar (e.g. 'es', 'fr', 'en-US').
     */
    public function locale(string $locale): static
    {
        $this->locale = $locale;
        $this->useCalendar = true;

        return $this;
    }

    /**
     * Weekday header label format: 'narrow' (default), 'short', or 'long'.
     */
    public function weekdayFormat(string $format): static
    {
        $this->weekdayFormat = $format;
        $this->useCalendar = true;

        return $this;
    }

    /**
     * Always render 6 week rows so the calendar height stays constant.
     */
    public function fixedWeeks(bool $condition = true): static
    {
        $this->fixedWeeks = $condition;
        $this->useCalendar = true;

        return $this;
    }

    /**
     * Earliest selectable date (ISO 'Y-m-d'); earlier dates are disabled.
     */
    public function minValue(string $date): static
    {
        $this->minValue = $date;
        $this->useCalendar = true;

        return $this;
    }

    /**
     * Latest selectable date (ISO 'Y-m-d'); later dates are disabled.
     */
    public function maxValue(string $date): static
    {
        $this->maxValue = $date;
        $this->useCalendar = true;

        return $this;
    }

    protected function getExtraData(): array
    {
        return [
            'useCalendar'    => $this->useCalendar,
            'numberOfMonths' => $this->numberOfMonths,
            'locale'         => $this->locale,
            'weekdayFormat'  => $this->weekdayFormat,
            'fixedWeeks'     => $this->fixedWeeks,
            'minValue'       => $this->minValue,
            'maxValue'       => $this->maxValue,
        ];
    }

    /**
     * @param array{from?: string|null, to?: string|null}|mixed $value
     */
    public function apply(Builder $query, mixed $value): void
    {
        if ($this->query !== null) {
            ($this->query)($query, $value);

            return;
        }

        if (!is_array($value)) {
            return;
        }

        $attribute = $this->attribute ?? $this->name;
        $from = $value['from'] ?? null;
        $to = $value['to'] ?? null;

        if ($from !== null && $from !== '') {
            $query->whereDate($attribute, '>=', $from);
        }

        if ($to !== null && $to !== '') {
            $query->whereDate($attribute, '<=', $to);
        }
    }
}
