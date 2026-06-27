<?php

declare(strict_types=1);

namespace Happones\Kinetix\Calendar;

use Closure;
use Happones\Kinetix\Data\CalendarData;
use Happones\Kinetix\Data\CalendarEventData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds a month-view calendar of events from an Eloquent query. Read-only: the
 * component navigates months client-side over the supplied events; scope the
 * window with ->query() if a model has many records.
 *
 *     Calendar::make(Event::query())
 *         ->dateColumn('starts_at')
 *         ->endColumn('ends_at')
 *         ->title('name')
 *         ->color(fn (Event $e) => $e->calendar->color)
 *         ->url(fn (Event $e) => route('events.show', $e));
 */
class Calendar
{
    protected string $dateColumn = 'date';

    protected ?string $endColumn = null;

    protected Closure|string $title = 'title';

    protected Closure|string|null $color = null;

    protected ?Closure $url = null;

    protected ?Closure $modifyQuery = null;

    protected ?string $heading = null;

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

    public function url(Closure $url): static
    {
        $this->url = $url;

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
        $events = $this->records()
            ->map(function (Model $r): ?CalendarEventData {
                $start = $r->getAttribute($this->dateColumn);

                if ($start === null) {
                    return null;
                }

                $end = $this->endColumn !== null ? $r->getAttribute($this->endColumn) : null;

                return new CalendarEventData(
                    id: $r->getKey(),
                    title: (string) $this->resolve($this->title, $r),
                    start: Carbon::parse($start)->toDateString(),
                    end: $end !== null ? Carbon::parse($end)->toDateString() : null,
                    color: $this->color === null ? null : ($this->resolve($this->color, $r) ?: null),
                    url: $this->url !== null ? ($this->url)($r) : null,
                );
            })
            ->filter()
            ->values()
            ->all();

        return new CalendarData(
            heading: $this->heading,
            events: $events,
        );
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
