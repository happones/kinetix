<?php

declare(strict_types=1);

namespace Happones\Kinetix\Resources;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Actions\ActionGroup;
use Happones\Kinetix\Actions\AssociateAction;
use Happones\Kinetix\Actions\AttachAction;
use Happones\Kinetix\Actions\DetachAction;
use Happones\Kinetix\Actions\DissociateAction;
use Happones\Kinetix\Actions\ExportAction;
use Happones\Kinetix\Actions\ImportAction;
use Happones\Kinetix\Data\RecordModalsData;
use Happones\Kinetix\Data\RelationManagerData;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Infolists\Infolist;
use Happones\Kinetix\Tables\Table;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
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
     * Lazy (Filament's `$isLazy`): the manager serializes only its tab stub
     * (title + badge) until it is the ACTIVE one (`?relation={relationship}`),
     * so its table queries never run for tabs nobody opened. The frontend
     * host requests the full payload automatically on activation. Note that
     * `getBadge()` still runs for the stub — keep it cheap on lazy managers.
     */
    protected static bool $isLazy = false;

    /**
     * Group label: managers sharing it render as ONE tab (their sections
     * stacked inside, each with its own heading). Passed through `__()` for
     * display; the raw value (slugged) is the group's stable `?relation=` key,
     * so shared links survive locale switches.
     */
    protected static ?string $group = null;

    /**
     * Collapsible: the manager's section gets a collapse toggle wherever its
     * heading renders (stacked layout / inside a group tab).
     */
    protected static bool $isCollapsible = false;

    /**
     * Start collapsed (implies collapsible).
     */
    protected static bool $isCollapsed = false;

    /**
     * The related-model attribute the attach modal labels and searches by
     * (Filament's `recordTitleAttribute`). Required for `AttachAction`.
     */
    protected static ?string $recordTitleAttribute = null;

    protected ?Model $parent = null;

    /**
     * Serialized pivot form for the attach modal, captured while wiring the
     * table's AttachAction (null when the action declares no form).
     *
     * @var array<string, mixed>|null
     */
    protected ?array $attachFormData = null;

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

    /**
     * The form the manager's create/edit MODALS render (the Filament
     * convention: a relation manager owns its own form). Override it to enable
     * `CreateAction::make()->modal('create')` / `EditAction::make()->modal('edit')`
     * on the table — created records are bound to the parent server-side, so
     * the schema never needs a parent select or foreign-key field.
     */
    public function form(Form $form): Form
    {
        return $form;
    }

    /**
     * The read-only detail the manager's View modal renders. Override it to
     * enable `ViewAction::make()->modal('view')` on the table.
     */
    public function infolist(Infolist $infolist): Infolist
    {
        return $infolist;
    }

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
     *
     * BelongsToMany joins the pivot table, and the raw relation builder has no
     * select — `SELECT *` across the join lets pivot columns clobber the
     * related model's at hydration (a pivot `id` overwrites the record's id, so
     * row actions would hit the WRONG record; `withTimestamps()` overwrites
     * `created_at`). Laravel's own get()/paginate() qualify the select the same
     * way; the Table calls the builder directly, so it's qualified here.
     */
    public function getRelationshipQuery(): Builder
    {
        $relation = $this->getRelation();
        $query    = $relation->getQuery();

        if ($relation instanceof BelongsToMany) {
            // Mirror Laravel's own shouldSelect(): the related model's columns
            // qualified (a pivot id/timestamp must never clobber them at
            // hydration) plus the pivot keys and every withPivot() column,
            // aliased under the accessor prefix so toData() can hydrate a real
            // Pivot model — that's what makes `TextColumn::make('pivot.role')`
            // resolve like any other dot column.
            $accessor = $relation->getPivotAccessor();
            $selects  = [$relation->getRelated()->qualifyColumn('*')];

            foreach ($this->pivotColumnNames($relation) as $column) {
                $selects[] = $relation->getTable().'.'.$column.' as '.$accessor.'_'.$column;
            }

            $query->select($selects);
        }

        return $query;
    }

    /**
     * The pivot columns worth carrying: both keys + every withPivot() column.
     *
     * @return array<int, string>
     */
    protected function pivotColumnNames(BelongsToMany $relation): array
    {
        return array_values(array_unique([
            $relation->getForeignPivotKeyName(),
            $relation->getRelatedPivotKeyName(),
            ...$relation->getPivotColumns(),
        ]));
    }

    public static function isLazy(): bool
    {
        return static::$isLazy;
    }

    public static function getGroup(): ?string
    {
        return static::$group !== null ? (string) __(static::$group) : null;
    }

    /**
     * The group's stable tab key: the RAW `$group` value slugged (never the
     * translation, so a shared `?relation=` link works in every locale).
     */
    public static function getGroupKey(): ?string
    {
        return static::$group !== null ? str(static::$group)->slug()->toString() : null;
    }

    /**
     * Whether this manager should serialize its FULL payload: always for
     * eager managers; for lazy ones only when it is the active tab
     * (`?relation=` matches the relationship — or the group key, so opening
     * a group tab loads ALL its lazy members in one request). Deliberately
     * never "first tab by default" — the point of lazy is that the initial
     * page render runs none of the manager's queries, even when its tab
     * starts active.
     */
    protected function shouldSerializeTable(): bool
    {
        if (! static::$isLazy) {
            return true;
        }

        $active = request('relation');

        return $active === static::$relationship
            || (static::getGroupKey() !== null && $active === static::getGroupKey());
    }

    /**
     * The lazy stub: enough for the tabs host to render the tab (and its
     * badge) — no table, no descriptor, no queries beyond `getBadge()`.
     * Serialize-time misconfiguration guards (export inside a manager, bad
     * pivot columns…) run when the manager LOADS, not here.
     */
    protected function toDeferredData(): RelationManagerData
    {
        return new RelationManagerData(
            title: static::getTitle(),
            relationship: static::$relationship,
            table: null,
            badge: $this->getBadge(),
            badgeColor: $this->getBadgeColor(),
            deferred: true,
            group: static::getGroup(),
            groupKey: static::getGroupKey(),
            collapsible: static::$isCollapsible || static::$isCollapsed,
            collapsed: static::$isCollapsed,
        );
    }

    public function toData(): RelationManagerData
    {
        if (! $this->shouldSerializeTable()) {
            return $this->toDeferredData();
        }

        $relation = $this->getRelation();

        $table = $this->table(
            Table::make($this->getRelationshipQuery())->queryPrefix(static::$relationship.'_')
        );

        // recordModals() resolves records through the RESOURCE's query, not
        // through this parent's relationship — its create endpoint would not
        // stamp the parent FK and its update/delete would reach ANY record of
        // the resource. Refuse the combination: relation managers get modal
        // CRUD by declaring form()/infolist() on the MANAGER and flagging
        // actions with ->modal('create'|'edit'|'view'|'delete').
        if ($table->getRecordModalsResource() !== null) {
            throw new RuntimeException(
                'recordModals() is not supported inside a relation manager ('.static::class.'): '
                .'the modal endpoints resolve through the resource query, not the parent relationship. '
                .'Declare form()/infolist() on the manager and use ->modal() actions instead — '
                .'the manager wires the parent-bound endpoints automatically.'
            );
        }

        if (static::$readOnly) {
            $table->recordActions([])->toolbarActions([])->bulkActions([])
                ->footerActions([])->emptyStateActions([]);
        }

        // Cell-update / drag-reorder writes resolve through the PARENT's
        // relationship (the captured where-scope can't express a BelongsToMany
        // membership — its equality lives on the pivot table).
        $table->writeRelation($this->parent::class, $this->parent->getKey(), static::$relationship);

        if ($relation instanceof BelongsToMany) {
            $this->wirePivotColumns($table, $relation);
        }

        $this->rejectUnscopedBulkTransfers($table);

        $wantsAttachDetach = $this->wireAttachDetach($table, $relation);
        $wantsAssociate    = $this->wireAssociateDissociate($table, $relation);
        $modalModes        = $this->collectModalModes($table);
        $exportActions     = $this->collectExportActions($table, $relation);

        $descriptor = ($wantsAttachDetach || $wantsAssociate || $modalModes !== [] || $exportActions !== [])
            ? $this->mintDescriptor()
            : null;

        if ($modalModes !== [] && $descriptor !== null) {
            $this->wireRecordModals($table, $relation, $descriptor, $modalModes);
        }

        foreach ($exportActions as $exportAction) {
            $exportAction->scopeToRelation((string) $descriptor);
        }

        return new RelationManagerData(
            title: static::getTitle(),
            relationship: static::$relationship,
            table: $table->toData(),
            badge: $this->getBadge(),
            badgeColor: $this->getBadgeColor(),
            descriptor: $descriptor,
            attachForm: $this->attachFormData,
            group: static::getGroup(),
            groupKey: static::getGroupKey(),
            collapsible: static::$isCollapsible || static::$isCollapsed,
            collapsed: static::$isCollapsed,
        );
    }

    /**
     * Signed descriptor: parent + relation + manager, bound to the user it was
     * minted for and expiring — the contract every relation endpoint
     * (record CRUD, attach/detach, associate/dissociate) re-validates.
     */
    protected function mintDescriptor(): string
    {
        $ttl = config('kinetix.tables.token_ttl', 1440);

        return Crypt::encrypt([
            'parent'   => $this->parent::class,
            'key'      => $this->parent->getKey(),
            'relation' => static::$relationship,
            'manager'  => static::class,
            'title'    => static::$recordTitleAttribute,
            'user'     => auth()->id(),
            'expires'  => is_numeric($ttl) && (int) $ttl > 0
                ? now()->getTimestamp() + ((int) $ttl * 60)
                : null,
        ]);
    }

    /**
     * Every action across the table's surfaces, with groups flattened.
     *
     * @return array<int, Action>
     */
    protected function allTableActions(Table $table): array
    {
        $flat = [];

        $surfaces = [
            ...$table->getToolbarActions(),
            ...$table->getRecordActions(),
            ...$table->getBulkActions(),
            ...$table->getFooterActions(),
            ...$table->getEmptyStateActions(),
        ];

        foreach ($surfaces as $action) {
            if ($action instanceof ActionGroup) {
                $flat = [...$flat, ...$action->getActions()];

                continue;
            }

            if ($action instanceof Action) {
                $flat[] = $action;
            }
        }

        return $flat;
    }

    /**
     * An ImportAction inside a relation manager would create UNATTACHED rows —
     * the importer knows nothing about the parent relationship. Refuse loudly
     * instead of shipping a foot-gun. (Exports ARE supported: they are wired
     * to the parent's relationship — see {@see collectExportActions()}.)
     */
    protected function rejectUnscopedBulkTransfers(Table $table): void
    {
        foreach ([...$table->getToolbarActions(), ...$table->getFooterActions()] as $action) {
            $candidates = $action instanceof ActionGroup ? $action->getActions() : [$action];

            foreach ($candidates as $candidate) {
                if ($candidate instanceof ImportAction) {
                    throw new RuntimeException(
                        'ImportAction is not supported inside a relation manager ('
                        .static::class.'): imported rows would not be attached to the parent. '
                        .'Import from the related resource\'s own index instead.'
                    );
                }
            }
        }
    }

    /**
     * The ExportActions across the table's surfaces, validated for relation
     * scoping: each must be wired to an exporter (a bare request/url export
     * can't be scoped) whose model IS the relation's related model. They are
     * scoped after the descriptor is minted — the export then runs THROUGH
     * the parent's relationship (ids of a bulk export narrow further).
     *
     * @return array<int, ExportAction>
     */
    protected function collectExportActions(Table $table, Relation $relation): array
    {
        $exportActions = array_values(array_filter(
            $this->allTableActions($table),
            static fn (Action $action): bool => $action instanceof ExportAction,
        ));

        $related = $relation->getRelated()::class;

        foreach ($exportActions as $action) {
            $exporterClass = $action->getExporterClass();

            if ($exporterClass === null) {
                throw new RuntimeException(
                    'An ExportAction inside a relation manager ('.static::class.') must be wired via '
                    .'->exporter(): a custom request/url export cannot be scoped to the parent\'s relation.'
                );
            }

            if ($exporterClass::getModel() !== $related) {
                throw new RuntimeException(
                    class_basename($exporterClass).' exports '.$exporterClass::getModel().' but '
                    .static::class." manages {$related} — the exporter's model must match the relation."
                );
            }
        }

        return $exportActions;
    }

    /**
     * The distinct ->modal() modes declared across the table's actions.
     *
     * @return array<int, string>
     */
    protected function collectModalModes(Table $table): array
    {
        return array_values(array_unique(array_filter(array_map(
            static fn (Action $action): ?string => $action->getModalMode(),
            $this->allTableActions($table),
        ))));
    }

    /**
     * Enable in-table modal CRUD for the ->modal() actions the table declared:
     * ships a RecordModalsData scoped to the RELATION endpoint (create binds to
     * the parent server-side; edit/view/delete resolve through the
     * relationship). Requires form() for create/edit and infolist() for view.
     *
     * @param array<int, string> $modes
     */
    protected function wireRecordModals(Table $table, Relation $relation, string $descriptor, array $modes): void
    {
        $related = $relation->getRelated();

        // Permissions are INHERITED from the related model's own policy — a
        // ProductPolicy written for the Products resource governs the manager
        // too, no separate permissions exist. Edit/View/Delete already check
        // view/update/delete per record (their constructors set it); Create
        // has no record, so gate it against the class here unless the manager
        // configured its own rule.
        foreach ($this->allTableActions($table) as $action) {
            if ($action->getModalMode() === 'create' && ! $action->hasAuthorization()) {
                $action->authorize('create', $related::class);
            }
        }

        /** @var Form $createForm */
        $createForm  = $this->form(Form::make(new $related)->operation('create'))->fill();
        $hasForm     = $createForm->getFields()                                        !== [];
        $hasInfolist = $this->infolist(Infolist::make(new $related))->toData()->schema !== [];

        if (in_array('view', $modes, true) && ! $hasInfolist) {
            throw new RuntimeException(
                static::class." uses ->modal('view') but declares no infolist() — override infolist() on the manager."
            );
        }

        if (array_intersect(['create', 'edit'], $modes) !== [] && ! $hasForm) {
            throw new RuntimeException(
                static::class." uses ->modal('create'|'edit') but declares no form() — override form() on the manager."
            );
        }

        $source = (string) config('kinetix.tables.record_source', 'server');

        $table->setRecordModalsData(new RecordModalsData(
            enabled: true,
            token: $descriptor,
            source: $source === 'row' ? 'row' : 'server',
            hasForm: $hasForm,
            hasInfolist: $hasInfolist,
            createForm: $hasForm ? $createForm->toArray() : null,
            scope: 'relation',
        ));
    }

    /**
     * Pivot columns for a BelongsToMany table: hands the Table the pivot
     * metadata (so sort/search on `pivot.*` qualify against the JOINED pivot
     * table) and hydrates a real Pivot model per record from the aliased
     * selects — `TextColumn::make('pivot.role')` then resolves through
     * `data_get()` like any other dot column, formatting/badges included.
     *
     * Editable `pivot.*` columns write through the relation-bound cell-update
     * endpoint, which routes them to `updateExistingPivot()` on the pivot row
     * (never the related model) — but ONLY for `withPivot()` columns, so an
     * editable column outside that list is refused loudly here instead of
     * 403ing at edit time.
     */
    protected function wirePivotColumns(Table $table, BelongsToMany $relation): void
    {
        $accessor = $relation->getPivotAccessor();

        $table->pivotColumns($relation->getTable(), $accessor, $relation->getPivotColumns());

        foreach ($table->getColumns() as $column) {
            $name = $column->getName();

            if (! $column->isEditable() || ! str_starts_with($name, $accessor.'.')) {
                continue;
            }

            if (! in_array(substr($name, strlen($accessor) + 1), $relation->getPivotColumns(), true)) {
                throw new RuntimeException(
                    'Editable pivot column '.static::class.'::'.$name.' is not a withPivot() column — '
                    .'the cell-update endpoint can only write pivot columns the relationship declares.'
                );
            }
        }

        $prefix = $accessor.'_';

        $table->transformRecordsUsing(function (Model $record) use ($relation, $accessor, $prefix): Model {
            $attributes = [];

            foreach ($record->getAttributes() as $key => $value) {
                if (str_starts_with($key, $prefix)) {
                    $attributes[substr($key, strlen($prefix))] = $value;
                }
            }

            foreach (array_keys($attributes) as $column) {
                unset($record->{$prefix.$column});
            }

            $record->setRelation($accessor, $relation->newExistingPivot($attributes));

            return $record;
        });
    }

    /**
     * BelongsToMany managers get their Attach/Detach actions wired to this
     * manager's browser events. Attach/Detach on any other relation type is a
     * misconfiguration, so it throws instead of rendering dead buttons.
     * Returns whether any were wired (→ the descriptor is needed).
     */
    protected function wireAttachDetach(Table $table, Relation $relation): bool
    {
        $attachDetach = array_filter(
            $this->allTableActions($table),
            static fn (Action $action): bool => $action instanceof AttachAction || $action instanceof DetachAction,
        );

        if ($attachDetach === []) {
            return false;
        }

        if (! $relation instanceof BelongsToMany) {
            throw new RuntimeException(
                'AttachAction/DetachAction require a BelongsToMany relation — '
                .static::class.'::$relationship is a '.class_basename($relation).'.'
            );
        }

        foreach ($attachDetach as $action) {
            $action->forRelationship(static::$relationship);

            if ($action instanceof AttachAction && $action->getForm() !== []) {
                $this->attachFormData = $this->buildAttachForm($action, $relation)->fill()->toArray();
            }
        }

        return true;
    }

    /**
     * The pivot form an AttachAction declared, validated against the relation:
     * every field must be a `withPivot()` column, or the attach endpoint would
     * silently drop its state (it writes ONLY pivot columns to the pivot row).
     */
    protected function buildAttachForm(AttachAction $action, BelongsToMany $relation): Form
    {
        $form = Form::make()->schema($action->getForm())->operation('create');

        $pivotColumns = $relation->getPivotColumns();

        foreach (array_keys($form->getFields()) as $name) {
            if (! in_array($name, $pivotColumns, true)) {
                throw new RuntimeException(
                    'AttachAction form field "'.$name.'" ('.static::class.') is not a pivot column — '
                    .'declare it in the relationship\'s withPivot() so the attach endpoint can write it.'
                );
            }
        }

        return $form;
    }

    /**
     * The attach modal's pivot form, rebuilt server-side from the manager's own
     * table — the attach endpoint validates the submitted pivot data against
     * THIS, never against anything the client sent. Null when the table's
     * AttachAction declares no form (or the relation is not BelongsToMany).
     */
    public function getAttachForm(): ?Form
    {
        $relation = $this->getRelation();

        if (! $relation instanceof BelongsToMany) {
            return null;
        }

        $table = $this->table(Table::make($this->getRelationshipQuery()));

        foreach ($this->allTableActions($table) as $action) {
            if ($action instanceof AttachAction && $action->getForm() !== []) {
                return $this->buildAttachForm($action, $relation);
            }
        }

        return null;
    }

    /**
     * HasMany/MorphMany managers get their Associate/Dissociate actions wired
     * to this manager's browser events (re-parenting by foreign key). Any other
     * relation type throws instead of rendering dead buttons.
     * Returns whether any were wired (→ the descriptor is needed).
     */
    protected function wireAssociateDissociate(Table $table, Relation $relation): bool
    {
        $associateDissociate = array_filter(
            $this->allTableActions($table),
            static fn (Action $action): bool => $action instanceof AssociateAction || $action instanceof DissociateAction,
        );

        if ($associateDissociate === []) {
            return false;
        }

        if (! $relation instanceof HasMany && ! $relation instanceof MorphMany) {
            throw new RuntimeException(
                'AssociateAction/DissociateAction require a HasMany/MorphMany relation — '
                .static::class.'::$relationship is a '.class_basename($relation).'.'
            );
        }

        foreach ($associateDissociate as $action) {
            $action->forRelationship(static::$relationship);
        }

        return true;
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
