<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support;

use DateTimeInterface;
use Illuminate\Contracts\Support\Arrayable;
use JsonSerializable;

/**
 * A weekly business-hours schedule: per-day enable flag + one or more
 * `HH:MM` time ranges. The value object behind the `BusinessHours` form
 * field, the `AsWeeklySchedule` cast and the `kinetix_weekly_schedule`
 * validation rule:
 *
 *     $venue->hours->isOpenAt(now($venue->timezone));   // bool
 *     $venue->hours->effectiveSchedule();               // full normalized week
 *
 * A range whose end is at or before its start wraps past midnight
 * (`22:00–02:00` = open into the next day). Times compare in the given
 * date's own timezone — convert before asking.
 */
final class WeeklySchedule implements Arrayable, JsonSerializable
{
    public const DAYS = ['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday', 'sunday'];

    /**
     * @param array<string, array{enabled: bool, ranges: array<int, array{start: string, end: string}>}> $days
     */
    private function __construct(private readonly array $days) {}

    /**
     * Build from any loose input (form payload, decoded JSON, another
     * schedule), NORMALIZING as it goes: unknown keys dropped, invalid time
     * ranges discarded, enabled days without a single valid range disabled.
     * Use {@see validate()} first when malformed input should be an error
     * instead.
     */
    public static function fromArray(mixed $value): self
    {
        if ($value instanceof self) {
            return $value;
        }

        if (is_string($value)) {
            $value = json_decode($value, true);
        }

        $value = is_array($value) ? $value : [];
        $days  = [];

        foreach (self::DAYS as $day) {
            $raw    = is_array($value[$day] ?? null) ? $value[$day] : [];
            $ranges = [];

            foreach (is_array($raw['ranges'] ?? null) ? $raw['ranges'] : [] as $range) {
                if (
                    is_array($range)
                    && self::isTime($range['start'] ?? null)
                    && self::isTime($range['end'] ?? null)
                    && $range['start'] !== $range['end']
                ) {
                    $ranges[] = ['start' => $range['start'], 'end' => $range['end']];
                }
            }

            $days[$day] = [
                'enabled' => (bool) ($raw['enabled'] ?? false) && $ranges !== [],
                'ranges'  => $ranges,
            ];
        }

        return new self($days);
    }

    /**
     * The conventional default: Monday–Friday 09:00–17:00, weekend closed
     * (with the same range seeded so enabling a weekend day starts sane).
     */
    public static function businessWeek(): self
    {
        $days = [];

        foreach (self::DAYS as $day) {
            $days[$day] = [
                'enabled' => ! in_array($day, ['saturday', 'sunday'], true),
                'ranges'  => [['start' => '09:00', 'end' => '17:00']],
            ];
        }

        return new self($days);
    }

    /**
     * STRICT structural validation for raw input (the
     * `kinetix_weekly_schedule` rule): array, day keys only, `HH:MM` times,
     * start ≠ end, and every enabled day carries at least one range. Unlike
     * {@see fromArray()}, nothing is silently repaired.
     */
    public static function validate(mixed $value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach ($value as $day => $entry) {
            if (! in_array($day, self::DAYS, true) || ! is_array($entry)) {
                return false;
            }

            $ranges = $entry['ranges'] ?? [];

            if (! is_array($ranges)) {
                return false;
            }

            foreach ($ranges as $range) {
                if (
                    ! is_array($range)
                    || ! self::isTime($range['start'] ?? null)
                    || ! self::isTime($range['end'] ?? null)
                    || $range['start'] === $range['end']
                ) {
                    return false;
                }
            }

            if (($entry['enabled'] ?? false) && $ranges === []) {
                return false;
            }
        }

        return true;
    }

    /**
     * Whether the schedule is open at the given moment — in that moment's own
     * timezone. Handles overnight ranges: friday `22:00–02:00` keeps saturday
     * 01:00 open through friday's entry.
     */
    public function isOpenAt(DateTimeInterface $at): bool
    {
        $day  = strtolower($at->format('l'));
        $time = $at->format('H:i');

        foreach ($this->rangesFor($day) as $range) {
            if ($range['start'] < $range['end']) {
                if ($time >= $range['start'] && $time < $range['end']) {
                    return true;
                }
            } elseif ($time >= $range['start']) {
                // Overnight range, evening side (open until midnight).
                return true;
            }
        }

        // The previous day's overnight ranges spill into this morning.
        $yesterday = self::DAYS[(array_search($day, self::DAYS, true) + 6) % 7];

        foreach ($this->rangesFor($yesterday) as $range) {
            if ($range['end'] <= $range['start'] && $time < $range['end']) {
                return true;
            }
        }

        return false;
    }

    public function isOpenNow(?string $timezone = null): bool
    {
        return $this->isOpenAt(now($timezone));
    }

    public function isEnabled(string $day): bool
    {
        return $this->days[strtolower($day)]['enabled'] ?? false;
    }

    /**
     * The ENABLED ranges for a day (empty when the day is off).
     *
     * @return array<int, array{start: string, end: string}>
     */
    public function rangesFor(string $day): array
    {
        $day = strtolower($day);

        if (! ($this->days[$day]['enabled'] ?? false)) {
            return [];
        }

        return $this->days[$day]['ranges'];
    }

    /**
     * The full normalized week — every day present with its enabled flag and
     * ranges, ready for storage or the editor.
     *
     * @return array<string, array{enabled: bool, ranges: array<int, array{start: string, end: string}>}>
     */
    public function effectiveSchedule(): array
    {
        return $this->days;
    }

    /**
     * @return array<string, array{enabled: bool, ranges: array<int, array{start: string, end: string}>}>
     */
    public function toArray(): array
    {
        return $this->days;
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }

    private static function isTime(mixed $value): bool
    {
        return is_string($value) && preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $value) === 1;
    }
}
