<?php

declare(strict_types=1);

namespace Happones\Kinetix\Support\Concerns;

use Carbon\CarbonInterface;
use Illuminate\Support\Carbon;

/**
 * Locale-aware date/datetime formatting for table columns and infolist entries.
 *
 * With no explicit format, values render through Carbon's `isoFormat()` in the
 * application locale, using the tokens from `config('kinetix.formats')` —
 * "Jul 9, 2026" in en, "9 jul 2026" in es, automatically. Passing a format
 * keeps the legacy behaviour (a plain, non-localized PHP `format()` string);
 * `isoDate()` / `isoDateTime()` take localized isoFormat tokens instead, and
 * `locale()` overrides the locale per column/entry.
 */
trait FormatsDates
{
    /**
     * Plain PHP format() string (non-localized, back-compat).
     */
    protected ?string $dateFormat = null;

    /**
     * Carbon isoFormat() tokens, rendered in the resolved locale.
     */
    protected ?string $isoDateFormat = null;

    protected ?string $dateLocale = null;

    /**
     * Format as a date. With no argument, uses the localized default from
     * `config('kinetix.formats.date')` (isoFormat, application locale). With a
     * format string, uses a plain PHP `format()` (not localized).
     */
    public function date(?string $format = null): static
    {
        if ($format !== null) {
            $this->dateFormat = $format;

            return $this;
        }

        $this->isoDateFormat = (string) config('kinetix.formats.date', 'll');

        return $this;
    }

    /**
     * Format as a datetime. Same semantics as {@see date()}, defaulting to
     * `config('kinetix.formats.datetime')`.
     */
    public function dateTime(?string $format = null): static
    {
        if ($format !== null) {
            $this->dateFormat = $format;

            return $this;
        }

        $this->isoDateFormat = (string) config('kinetix.formats.datetime', 'lll');

        return $this;
    }

    /**
     * Format with Carbon isoFormat tokens in the resolved locale
     * (Filament-compatible), e.g. `LL` → "9 de julio de 2026" in es.
     */
    public function isoDate(?string $format = null): static
    {
        $this->isoDateFormat = $format ?? (string) config('kinetix.formats.date', 'll');

        return $this;
    }

    /**
     * Filament-compatible datetime variant of {@see isoDate()}.
     */
    public function isoDateTime(?string $format = null): static
    {
        $this->isoDateFormat = $format ?? (string) config('kinetix.formats.datetime', 'lll');

        return $this;
    }

    /**
     * Override the formatting locale for this column/entry (defaults to the
     * application locale).
     */
    public function locale(string $locale): static
    {
        $this->dateLocale = $locale;

        return $this;
    }

    protected function hasDateFormatting(): bool
    {
        return $this->dateFormat !== null || $this->isoDateFormat !== null;
    }

    /**
     * Apply the configured date formatting to a raw value (Carbon instance or
     * parseable string). Non-date values pass through untouched.
     */
    protected function formatDateValue(mixed $value): mixed
    {
        if (! $this->hasDateFormatting()) {
            return $value;
        }

        if (! $value instanceof CarbonInterface) {
            if (! is_string($value)) {
                return $value;
            }

            $value = Carbon::parse($value);
        }

        if ($this->isoDateFormat !== null) {
            return $value->locale($this->dateLocale ?? app()->getLocale())->isoFormat($this->isoDateFormat);
        }

        return $value->format((string) $this->dateFormat);
    }
}
