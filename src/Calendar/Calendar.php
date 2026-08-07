<?php

declare(strict_types=1);

namespace Happones\Kinetix\Calendar;

use Closure;
use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Data\CalendarData;
use Happones\Kinetix\Data\CalendarEventData;
use Happones\Kinetix\Support\KinetixTimezone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;

/**
 * Builds a month/week/day-view calendar of events from an Eloquent query.
 * Read-only by default: the component navigates client-side over the supplied
 * events; scope the window with ->query() if a model has many records.
 * Opt into drag-and-drop rescheduling with ->moveable() — dragging an event
 * to another day/slot persists the new start (guarded by a signed
 * descriptor, like the Kanban board's moves).
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

    /**
     * @var array<int, Action>
     */
    protected array $eventActions = [];

    protected bool $moveable = false;

    protected ?string $moveAbility = null;

    /**
     * @var array<string, mixed>
     */
    protected array $moveScope = [];

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

    /**
     * Per-event actions (edit/delete/custom), shown in the built-in event
     * details modal/sheet — resolved against each event's underlying record,
     * exactly like `Table::recordActions()`. Optional: omit for a purely
     * read-only calendar.
     *
     *     Calendar::make(Event::query())
     *         ->eventActions([
     *             Action::make('edit')->icon('pencil')->inertiaVisit(fn ($e) => route('events.edit', $e)),
     *             Action::make('delete')->icon('trash')->color('danger')
     *                 ->requiresConfirmation()->inertiaVisit(fn ($e) => route('events.destroy', $e), ['method' => 'delete']),
     *         ]);
     *
     * @param array<int, Action> $actions
     */
    public function eventActions(array $actions): static
    {
        $this->eventActions = $actions;

        return $this;
    }

    /**
     * Let events be dragged to another day (month view) or hour slot
     * (week/day views). The new start persists to `dateColumn` and the end
     * shifts by the same delta, so durations are preserved. Guarded like
     * Kanban moves: a signed descriptor plus the host's policy — pair with
     * `authorizeMove()` / `moveScope()` to bound who can move what.
     */
    public function moveable(bool $condition = true): static
    {
        $this->moveable = $condition;

        return $this;
    }

    /**
     * The Gate ability checked against the record on every move (via the
     * host's policy). Defaults to `update` whenever the model has a
     * registered policy — call this to check a different ability.
     */
    public function authorizeMove(string $ability): static
    {
        $this->moveAbility = $ability;

        return $this;
    }

    /**
     * Constraints (column => value) the record must match to be movable —
     * evaluated now (in the request) and enforced on the move endpoint's
     * lookup. The tenant guard for calendars without a policy:
     *
     *     ->moveScope(['team_id' => $request->user()->currentTeam->getKey()])
     *
     * @param array<string, mixed> $constraints serializable values only
     */
    public function moveScope(array $constraints): static
    {
        $this->moveScope = $constraints;

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

                $resolvedActions = [];
                foreach ($this->eventActions as $action) {
                    $data = $action->toData($r);
                    if ($data !== null) {
                        $resolvedActions[] = $data;
                    }
                }

                return new CalendarEventData(
                    id: $r->getKey(),
                    title: (string) $this->resolve($this->title, $r),
                    start: $start->toIso8601String(),
                    end: $end?->toIso8601String(),
                    allDay: $allDay,
                    color: $this->color === null ? null : ($this->resolve($this->color, $r) ?: null),
                    url: $this->url !== null ? ($this->url)($r) : null,
                    description: $this->description === null ? null : ($this->resolve($this->description, $r) ?: null),
                    actions: $resolvedActions,
                );
            })
            ->filter()
            ->values()
            ->all();

        return new CalendarData(
            heading: $this->heading,
            events: $events,
            timezone: $timezone,
            model: $this->moveable ? $this->buildMoveDescriptor() : null,
        );
    }

    /**
     * Mint the signed descriptor {@see CalendarMoveController} trusts: the
     * model, the date columns to rewrite, the ability and scope bounding the
     * move, plus the user it was minted for and an expiry so a leaked token
     * isn't replayable by someone else.
     */
    protected function buildMoveDescriptor(): string
    {
        $ttl = config('kinetix.tables.token_ttl', 1440);

        return Crypt::encrypt([
            'model'       => $this->getModelClass(),
            'dateColumn'  => $this->dateColumn,
            'endColumn'   => $this->endColumn,
            'moveAbility' => $this->moveAbility,
            'moveScope'   => $this->moveScope,
            'user'        => auth()->id(),
            'expires'     => is_numeric($ttl) && (int) $ttl > 0
                ? now()->getTimestamp() + ((int) $ttl * 60)
                : null,
        ]);
    }

    /**
     * @return class-string<Model>
     */
    public function getModelClass(): string
    {
        if ($this->queryOrModel instanceof Builder) {
            return $this->queryOrModel->getModel()::class;
        }

        if (is_string($this->queryOrModel)) {
            return $this->queryOrModel;
        }

        return $this->queryOrModel::class;
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
    protected function records(): Collection
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
