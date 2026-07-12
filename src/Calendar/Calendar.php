<?php

declare(strict_types=1);

namespace Happones\Kinetix\Calendar;

use Closure;
use Happones\Kinetix\Data\CalendarData;
use Happones\Kinetix\Data\CalendarEventData;
use Happones\Kinetix\Support\KinetixTimezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds a month/week/day-view calendar of events from an Eloquent query.
 * Read-only: the component navigates client-side over the supplied events;
 * scope the window with ->query() if a model has many records.
 *
 *     Calendar::make(Event::query())
 *         ->dateColumn('starts_at')
 *         ->endColumn('ends_at')
 *         ->title('name')
 *         ->color(fn (Event $e) => $e->calendar->color)
 *         ->url(fn (Event $e) => route('events.show', $e));
 *
 * Events are serialized as absolute-instant ISO-8601 datetimes (never
 * date-only strings), so the frontend can correctly re-render them in any
 * timezone regardless of which timezone the server used to format them.
 */
class Calendar
{
    protected string $dateColumn = 'date';

    protected ?string $endColumn = null;

    protected Closure|string $title = 'title';

    protected Closure|string|null $color = null;

    protected Closure|string|null $description = null;

    protected ?Closure $url = null;

    protected ?Closure $modifyQuery = null;

    protected ?string $heading = null;

    protected string|Closure|null $timezone = null;

    public function __construct(protected mixed $queryOrModel) {}

    public static function make(mixed $queryOrModel): static
    {
        return new static($queryOrModel);
    }

    public function dateColumn(string $column): static
    {
        $this->dateColumn = $column;

        return $this;
    }

    /**
     * An end-date column for multi-day events (inclusive).
     */
    public function endColumn(string $column): static
    {
        $this->endColumn = $column;

        return $this;
    }

    public function title(Closure|string $title): static
    {
        $this->title = $title;

        return $this;
    }

    public function color(Closure|string|null $color): static
    {
        $this->color = $color;

        return $this;
    }

    public function description(Closure|string|null $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function url(Closure $url): static
    {
        $this->url = $url;

        return $this;
    }

    /**
     * The IANA timezone events resolve against (e.g. `America/Mexico_City`,
     * or a closure like `fn () => auth()->user()->timezone`). Defaults to
     * `config('app.timezone')`. Since events serialize as absolute-instant
     * ISO-8601 datetimes, this mostly matters for `allDay` auto-detection
     * (whether a start/end falls exactly at midnight) — the frontend can
     * still re-render in a different timezone via its own `timezone` prop.
     */
    public function timezone(string|Closure|null $timezone): static
    {
        $this->timezone = $timezone;

        return $this;
    }

    public function query(Closure $callback): static
    {
        $this->modifyQuery = $callback;

        return $this;
    }

    public function heading(string $heading): static
    {
        $this->heading = $heading;

        return $this;
    }

    public function toData(): CalendarData
    {
        $timezone = $this->resolveTimezone();

        $events = $this->records()
            ->map(function (Model $r) use ($timezone): ?CalendarEventData {
                $start = $r->getAttribute($this->dateColumn);

                if ($start === null) {
                    return null;
                }

                $start = Carbon::parse($start)->setTimezone($timezone);
                $end   = $this->endColumn !== null ? $r->getAttribute($this->endColumn) : null;
                $end   = $end             !== null ? Carbon::parse($end)->setTimezone($timezone) : null;

                $allDay = $start->format('H:i:s') === '00:00:00'
                    && ($end === null || $end->format('H:i:s') === '00:00:00');

                return new CalendarEventData(
                    id: $r->getKey(),
                    title: (string) $this->resolve($this->title, $r),
                    start: $start->toIso8601String(),
                    end: $end?->toIso8601String(),
                    allDay: $allDay,
                    color: $this->color === null ? null : ($this->resolve($this->color, $r) ?: null),
                    url: $this->url !== null ? ($this->url)($r) : null,
                    description: $this->description === null ? null : ($this->resolve($this->description, $r) ?: null),
                );
            })
            ->filter()
            ->values()
            ->all();

        return new CalendarData(
            heading: $this->heading,
            events: $events,
            timezone: $timezone,
        );
    }

    protected function resolveTimezone(): string
    {
        if ($this->timezone instanceof Closure) {
            return ($this->timezone)() ?? KinetixTimezone::default();
        }

        return $this->timezone ?? KinetixTimezone::default();
    }

    /**
     * @return Collection<int, Model>
     */
    protected function records()
    {
        $query = $this->resolveQuery();

        if ($this->modifyQuery !== null) {
            ($this->modifyQuery)($query);
        }

        return $query->get();
    }

    protected function resolveQuery(): Builder
    {
        if ($this->queryOrModel instanceof Builder) {
            return $this->queryOrModel;
        }

        /** @var class-string<Model> $class */
        $class = is_string($this->queryOrModel) ? $this->queryOrModel : $this->queryOrModel::class;

        return $class::query();
    }

    protected function resolve(Closure|string $accessor, Model $record): mixed
    {
        if ($accessor instanceof Closure) {
            return $accessor($record);
        }

        return $record->getAttribute($accessor);
    }
}
