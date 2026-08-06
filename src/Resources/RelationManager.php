<?php

declare(strict_types=1);

namespace Happones\Kinetix\Resources;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Actions\ActionGroup;
use Happones\Kinetix\Actions\AssociateAction;
use Happones\Kinetix\Actions\AttachAction;
use Happones\Kinetix\Actions\DetachAction;
use Happones\Kinetix\Actions\DissociateAction;
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
            $table->recordActions([])->toolbarActions([])->bulkActions([]);
        }

        $wantsAttachDetach = $this->wireAttachDetach($table, $relation);
        $wantsAssociate    = $this->wireAssociateDissociate($table, $relation);
        $modalModes        = $this->collectModalModes($table);

        $descriptor = ($wantsAttachDetach || $wantsAssociate || $modalModes !== [])
            ? $this->mintDescriptor()
            : null;

        if ($modalModes !== [] && $descriptor !== null) {
            $this->wireRecordModals($table, $relation, $descriptor, $modalModes);
        }

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

        foreach ([...$table->getToolbarActions(), ...$table->getRecordActions(), ...$table->getBulkActions()] as $action) {
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
        }

        return true;
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
