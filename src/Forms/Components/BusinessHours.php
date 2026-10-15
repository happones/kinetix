<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use Happones\Kinetix\Support\WeeklySchedule;
use Illuminate\Database\Eloquent\Model;

/**
 * Weekly business-hours editor: one row per day (enable toggle + one or more
 * `HH:MM` time ranges, picked with Kinetix time pickers + "apply to all
 * days"). Stores the normalized schedule
 * array — pair the column with the `AsWeeklySchedule` cast to read it back
 * as a value object (`isOpenAt()` / `effectiveSchedule()`):
 *
 *     BusinessHours::make('hours');
 *
 * Defaults to Monday–Friday 09:00–17:00; validation
 * (`kinetix_weekly_schedule`) enforces the structure server-side.
 */
class BusinessHours extends Field
{
    public function __construct(string $name)
    {
        parent::__construct($name);

        $this->default(WeeklySchedule::businessWeek()->toArray());
        $this->rules(['array', 'kinetix_weekly_schedule']);
    }

    protected function getType(): string
    {
        return 'business-hours';
    }

    protected bool $hour12 = true;

    protected int $minuteStep = 5;

    /**
     * Pick times on a 12-hour clock with AM/PM (the default).
     */
    public function twelveHour(bool $condition = true): static
    {
        $this->hour12 = $condition;

        return $this;
    }

    /**
     * Pick times on a 24-hour clock.
     */
    public function twentyFourHour(bool $condition = true): static
    {
        $this->hour12 = ! $condition;

        return $this;
    }

    /**
     * Minute granularity of the time pickers (default 5).
     */
    public function minuteStep(int $step): static
    {
        $this->minuteStep = max(1, $step);

        return $this;
    }

    /**
     * @return array{useCalendar: bool, locale: ?string, minuteStep: int, hour12: bool}
     */
    protected function dateConfig(): array
    {
        return ['useCalendar' => true, 'locale' => null, 'minuteStep' => $this->minuteStep, 'hour12' => $this->hour12];
    }

    /**
     * The editor always receives the FULL normalized week — a cast value
     * object, a stored JSON string or a partial array all flatten the same.
     */
    public function hydrate(mixed $value, ?Model $record = null): mixed
    {
        if ($this->afterStateHydrated !== null) {
            return parent::hydrate($value, $record);
        }

        return $value === null ? null : WeeklySchedule::fromArray($value)->toArray();
    }

    /**
     * Normalize on the way out too (validation already rejected malformed
     * input; this flattens the payload into the canonical full-week shape).
     */
    public function dehydrate(mixed $value, ?Model $record = null): mixed
    {
        if ($this->dehydrateStateUsing !== null) {
            return parent::dehydrate($value, $record);
        }

        return $value === null ? null : WeeklySchedule::fromArray($value)->toArray();
    }
}
