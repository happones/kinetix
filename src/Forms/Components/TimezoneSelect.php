<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components;

use DateTimeImmutable;
use DateTimeZone;

/**
 * A searchable time zone picker: every IANA zone, labelled with its current
 * UTC offset (`(UTC−06:00) America/Mexico City`) and ordered by it, so
 * typing a city or an offset finds it. The `timezone` rule is added.
 *
 *     TimezoneSelect::make('timezone')->default(config('app.timezone'));
 */
class TimezoneSelect extends Select
{
    public function __construct(string $name)
    {
        parent::__construct($name);

        $this->options(static::timezoneOptions())->searchable()->rules(['timezone']);
    }

    /**
     * Zone => label, ordered by offset then name.
     *
     * @return array<string, string>
     */
    public static function timezoneOptions(): array
    {
        $now   = new DateTimeImmutable('now');
        $zones = [];

        foreach (DateTimeZone::listIdentifiers() as $identifier) {
            $offset = (new DateTimeZone($identifier))->getOffset($now);
            $hours  = intdiv(abs($offset), 3600);
            $mins   = intdiv(abs($offset) % 3600, 60);
            $sign   = $offset < 0 ? '−' : '+';

            $zones[$identifier] = [
                $offset,
                sprintf('(UTC%s%02d:%02d) %s', $sign, $hours, $mins, str_replace('_', ' ', $identifier)),
            ];
        }

        uasort($zones, static fn (array $a, array $b): int => [$a[0], $a[1]] <=> [$b[0], $b[1]]);

        return array_map(static fn (array $zone): string => $zone[1], $zones);
    }
}
