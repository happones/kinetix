<?php

declare(strict_types=1);

namespace Happones\Kinetix\Forms\Components\Concerns;

use InvalidArgumentException;

/**
 * A date or time picker the reader can type into: a masked input in a chosen
 * format (`dd/mm/yyyy`, `mm/dd/yyyy hh:mm a`…), with the picker on a button
 * to its right. The value stored stays the ISO one the picker emits.
 *
 * Tokens: `dd`, `mm`, `yyyy` / `yy` in the date part; `HH` (24h) / `hh` (12h),
 * `mm` and `a` (AM/PM) in the time part — after the first space or an hour
 * token. Anything else is a separator.
 */
trait CanBeTyped
{
    protected bool $typed = false;

    protected ?string $inputFormat = null;

    /**
     * Type the value on the keyboard over a mask. Pass the format, or leave it
     * out for the reader's locale order (and the field's clock); `false` turns
     * the typed input off again.
     */
    public function typed(string|bool $format = true): static
    {
        if (is_string($format)) {
            $this->assertTypedFormat($format);
        }

        $this->typed       = $format !== false;
        $this->inputFormat = is_string($format) ? $format : null;

        return $this;
    }

    /**
     * Which parts the format must have: 'date', 'datetime', 'time' or 'month'.
     */
    abstract protected function typedKind(): string;

    /**
     * @return array{typed: bool, inputFormat: ?string}
     */
    protected function typedConfig(): array
    {
        return ['typed' => $this->typed, 'inputFormat' => $this->inputFormat];
    }

    /**
     * A format without a part its value needs can't be filled in — refused
     * where it's written, not when a reader meets an input that never
     * completes.
     */
    private function assertTypedFormat(string $format): void
    {
        $split = preg_match('/ |HH|hh/', $format, $match, PREG_OFFSET_CAPTURE) === 1 ? $match[0][1] : strlen($format);
        $date  = substr($format, 0, $split);
        $time  = substr($format, $split);

        $needs = match ($this->typedKind()) {
            'date'     => ['dd' => $date, 'mm' => $date, 'yy' => $date],
            'month'    => ['mm' => $date, 'yy' => $date],
            'time'     => ['hour' => $time, 'mm' => $time],
            'datetime' => ['dd' => $date, 'mm' => $date, 'yy' => $date, 'hour' => $time, 'minutes' => $time],
            default    => [],
        };

        $names = ['yy' => 'year (yyyy or yy)', 'hour' => 'hour (HH or hh)', 'minutes' => 'minutes (mm)'];

        foreach ($needs as $token => $part) {
            $found = match ($token) {
                'hour'    => str_contains($part, 'HH') || str_contains($part, 'hh'),
                'minutes' => str_contains($part, 'mm'),
                default   => str_contains($part, $token),
            };

            if (! $found) {
                throw new InvalidArgumentException(sprintf(
                    'The typed format [%s] has no %s for a %s field.',
                    $format,
                    $names[$token] ?? $token,
                    $this->typedKind(),
                ));
            }
        }

        if (str_contains($time, 'hh') && ! str_contains($time, 'a')) {
            throw new InvalidArgumentException("The typed format [{$format}] has a 12-hour hour (hh) without its AM/PM (a).");
        }
    }
}
