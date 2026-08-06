<?php

declare(strict_types=1);

namespace Happones\Kinetix\Resources;

use Happones\Kinetix\Actions\AttachAction;
use Happones\Kinetix\Actions\DetachAction;
use Happones\Kinetix\Data\RelationManagerData;
use Happones\Kinetix\Tables\Table;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Crypt;
use JsonSerializable;
use RuntimeException;

abstract class RelationManager implements Arrayable, JsonSerializable
{
    /**
     * The relationship method name on the parent model (e.g. 'posts').
     */
    protected static string $relationship;

    /**
     * Optional heading; passed through `__()` so a translation key works.
     * Defaults to a humanized relationship name.
     */
    protected static ?string $title = null;

    /**
     * Pages this relation manager appears on. Defaults to both the edit and the
     * view (show) page; restrict to one with e.g. `['view']`.
     *
     * @var array<int, string>
     */
    protected static array $visibleOn = ['edit', 'view'];

    /**
     * Badge color shown next to the title / on the tab (a Kinetix status
     * color: primary, gray, success, warning, danger, info).
     */
    protected static ?string $badgeColor = null;

    /**
     * Read-only: the table renders with NO record/toolbar/bulk actions,
     * whatever `table()` configured.
     */
    protected static bool $readOnly = false;

    /**
     * The related-model attribute the attach modal labels and searches by
     * (Filament's `recordTitleAttribute`). Required for `AttachAction`.
     */
    protected static ?string $recordTitleAttribute = null;

    protected ?Model $parent = null;

    public function __construct(?Model $parent = null)
    {
        $this->parent = $parent;
    }

    public static function make(?Model $parent = null): static
    {
        return new static($parent);
    }

    /**
     * Configure the table that lists the related records.
     */
    abstract public function table(Table $table): Table;

    public static function getTitle(): string
    {
        return static::$title !== null
            ? (string) __(static::$title)
            : (string) str(static::$relationship)->headline();
    }

    public static function getRelationship(): string
    {
        return static::$relationship;
    }

    /**
     * Whether this relation manager should be shown on the given page
     * ('edit' | 'view'). Page-level only — for per-record / per-user gating
     * override {@see canViewForRecord()}.
     */
    public static function isVisibleOn(string $page): bool
    {
        return in_array($page, static::$visibleOn, true);
    }

    /**
     * Whether this relation manager should be shown for the given PARENT
     * record on the given page — the Filament `canViewForRecord()` analogue.
     * Defaults to the page-level visibility; override for record- or
     * user-level logic:
     *
     *     public static function canViewForRecord(Model $parent, string $page): bool
     *     {
     *         return parent::canViewForRecord($parent, $page)
     *             && Gate::allows('viewComments', $parent);
     *     }
     */
    public static function canViewForRecord(Model $parent, string $page): bool
    {
        return static::isVisibleOn($page);
    }

    /**
     * Badge shown next to the title / on the tab (e.g. a record count).
     * Return null (the default) for no badge:
     *
     *     public function getBadge(): int|string|null
     *     {
     *         return $this->getRelationshipQuery()->count();
     *     }
     */
    public function getBadge(): int|string|null
    {
        return null;
    }

    public function getBadgeColor(): ?string
    {
        return static::$badgeColor;
    }

    /**
     * The parent's relationship OBJECT (BelongsToMany keeps its pivot).
     */
    public function getRelation(): Relation
    {
        if ($this->parent === null) {
            throw new RuntimeException('A parent record is required to resolve '.static::class.'.');
        }

        return $this->parent->{static::$relationship}();
    }

    /**
     * The Eloquent query for the parent's related records.
     */
    public function getRelationshipQuery(): Builder
    {
        return $this->getRelation()->getQuery();
    }

    public function toData(): RelationManagerData
    {
        $relation = $this->getRelation();

        $table = $this->table(
            Table::make($this->getRelationshipQuery())->queryPrefix(static::$relationship.'_')
        );

        // recordModals() resolves records through the RESOURCE's query, not
        // through this parent's relationship — its create endpoint would not
        // stamp the parent FK and its update/delete would reach ANY record of
        // the resource. Refuse the combination instead of shipping the hole.
        if ($table->getRecordModalsResource() !== null) {
            throw new RuntimeException(
                'recordModals() is not supported inside a relation manager ('.static::class.'): '
                .'the modal endpoints resolve through the resource query, not the parent relationship. '
                .'Use row actions pointing at your own routes instead.'
            );
        }

        if (static::$readOnly) {
            $table->recordActions([])->toolbarActions([])->bulkActions([]);
        }

        $descriptor = $this->wireAttachDetach($table, $relation);

        return new RelationManagerData(
            title: static::getTitle(),
            relationship: static::$relationship,
            table: $table->toData(),
            badge: $this->getBadge(),
            badgeColor: $this->getBadgeColor(),
            descriptor: $descriptor,
        );
    }

    /**
     * BelongsToMany managers get a signed descriptor (parent + relation,
     * user-bound, expiring) and their Attach/Detach actions are wired to this
     * manager's browser events. Attach/Detach on any other relation type is a
     * misconfiguration, so it throws instead of rendering dead buttons.
     */
    protected function wireAttachDetach(Table $table, Relation $relation): ?string
    {
        $attachDetach = array_filter(
            [...$table->getToolbarActions(), ...$table->getRecordActions(), ...$table->getBulkActions()],
            static fn (mixed $action): bool => $action instanceof AttachAction || $action instanceof DetachAction,
        );

        if (! $relation instanceof BelongsToMany) {
            if ($attachDetach !== []) {
                throw new RuntimeException(
                    'AttachAction/DetachAction require a BelongsToMany relation — '
                    .static::class.'::$relationship is a '.class_basename($relation).'.'
                );
            }

            return null;
        }

        foreach ($attachDetach as $action) {
            $action->forRelationship(static::$relationship);
        }

        $ttl = config('kinetix.tables.token_ttl', 1440);

        return Crypt::encrypt([
            'parent'   => $this->parent::class,
            'key'      => $this->parent->getKey(),
            'relation' => static::$relationship,
            'title'    => static::$recordTitleAttribute,
            'user'     => auth()->id(),
            'expires'  => is_numeric($ttl) && (int) $ttl > 0
                ? now()->getTimestamp() + ((int) $ttl * 60)
                : null,
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->toData()->toArray();
    }

    public function jsonSerialize(): mixed
    {
        return $this->toArray();
    }
}
