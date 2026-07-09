<?php

declare(strict_types=1);

namespace Happones\Kinetix\Kanban;

use BackedEnum;
use Closure;
use Happones\Kinetix\Data\KanbanCardData;
use Happones\Kinetix\Data\KanbanColumnData;
use Happones\Kinetix\Data\KanbanData;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use UnitEnum;

/**
 * A drag-and-drop board: records grouped into columns by a status attribute.
 * Dragging a card to another column persists the new status (guarded by a signed
 * descriptor, like the editable table cells).
 *
 *     Kanban::make(Task::query())
 *         ->statusColumn('status')
 *         ->statuses(['todo' => 'To Do', 'doing' => 'In Progress', 'done' => 'Done'])
 *         ->cardTitle('title')
 *         ->cardDescription(fn (Task $t) => $t->assignee?->name);
 */
class Kanban
{
    protected string $statusColumn = 'status';

    /**
     * @var array<string, array{label: string, color: string|null}>
     */
    protected array $statuses = [];

    protected Closure|string $cardTitle = 'title';

    protected Closure|string|null $cardDescription = null;

    protected ?Closure $modifyQuery = null;

    protected ?string $heading = null;

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

    public function statusColumn(string $column): static
    {
        $this->statusColumn = $column;

        return $this;
    }

    /**
     * @param array<string, string|array{label?: string, color?: string|null}> $statuses
     */
    public function statuses(array $statuses): static
    {
        foreach ($statuses as $key => $def) {
            if (is_string($def)) {
                $def = ['label' => $def];
            }

            $this->statuses[$key] = [
                'label' => $def['label'] ?? ucfirst($key),
                'color' => $def['color'] ?? null,
            ];
        }

        return $this;
    }

    public function cardTitle(Closure|string $title): static
    {
        $this->cardTitle = $title;

        return $this;
    }

    public function cardDescription(Closure|string|null $description): static
    {
        $this->cardDescription = $description;

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
     * The Gate ability checked against the record on every move (via the host's
     * policy). Defaults to `update` whenever the model has a registered policy —
     * call this to check a different ability.
     */
    public function authorizeMove(string $ability): static
    {
        $this->moveAbility = $ability;

        return $this;
    }

    /**
     * Constraints (column => value) the record must match to be movable —
     * evaluated now (in the request) and enforced on the move endpoint's
     * lookup. The tenant guard for boards without a policy:
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

    public function toData(): KanbanData
    {
        $records = $this->records();

        $columns = [];
        foreach ($this->statuses as $key => $def) {
            $cards = $records
                ->filter(fn (Model $r): bool => $this->statusKey($r) === (string) $key)
                ->map(fn (Model $r): KanbanCardData => new KanbanCardData(
                    id: $r->getKey(),
                    title: (string) $this->resolve($this->cardTitle, $r),
                    description: $this->cardDescription === null
                        ? null
                        : ($this->resolve($this->cardDescription, $r) ?: null),
                ))
                ->values()
                ->all();

            $columns[] = new KanbanColumnData(
                key: (string) $key,
                label: $def['label'],
                color: $def['color'],
                cards: $cards,
            );
        }

        return new KanbanData(
            heading: $this->heading,
            columns: $columns,
            model: Crypt::encrypt([
                'model'        => $this->getModelClass(),
                'statusColumn' => $this->statusColumn,
                'statuses'     => array_map(strval(...), array_keys($this->statuses)),
                'moveAbility'  => $this->moveAbility,
                'moveScope'    => $this->moveScope,
            ]),
        );
    }

    /**
     * The record's status as a board-column key — enum casts resolve to their
     * backing value (BackedEnum) or case name (UnitEnum).
     */
    protected function statusKey(Model $record): string
    {
        $value = $record->getAttribute($this->statusColumn);

        if ($value instanceof BackedEnum) {
            return (string) $value->value;
        }

        if ($value instanceof UnitEnum) {
            return $value->name;
        }

        return (string) $value;
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

    protected function resolve(Closure|string $accessor, Model $record): mixed
    {
        if ($accessor instanceof Closure) {
            return $accessor($record);
        }

        return $record->getAttribute($accessor);
    }
}
