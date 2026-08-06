<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support\Casts;

use Happones\Kinetix\Support\WeeklySchedule;
use Illuminate\Contracts\Database\Eloquent\CastsAttributes;
use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent cast for a weekly business-hours column (JSON/text):
 *
 *     protected $casts = ['hours' => AsWeeklySchedule::class];
 *
 *     $venue->hours;                       // ?WeeklySchedule
 *     $venue->hours?->isOpenAt(now());     // bool
 *     $venue->hours = ['monday' => ...];   // arrays/VOs both store normalized
 *
 * @implements CastsAttributes<WeeklySchedule|null, WeeklySchedule|array<string, mixed>|string|null>
 */
class AsWeeklySchedule implements CastsAttributes
{
    public function get(Model $model, string $key, mixed $value, array $attributes): ?WeeklySchedule
    {
        return $value === null ? null : WeeklySchedule::fromArray($value);
    }

    public function set(Model $model, string $key, mixed $value, array $attributes): ?string
    {
        if ($value === null) {
            return null;
        }

        return json_encode(WeeklySchedule::fromArray($value)->toArray());
    }
}
