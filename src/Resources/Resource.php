<?php

declare(strict_types=1);

namespace Happones\Kinetix\Resources;

use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Infolists\Infolist;
use Happones\Kinetix\Permissions\PermissionRegistry;
use Happones\Kinetix\Tables\Table;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route as RouteFacade;

abstract class Resource
{
    /**
     * The model class associated with this resource.
     */
    protected static ?string $model = null;

    /**
     * The navigation icon for the admin panel sidebar.
     */
    protected static ?string $navigationIcon = 'cube';

    /**
     * The navigation label for the admin panel sidebar.
     */
    protected static ?string $navigationLabel = null;

    /**
     * The navigation sort placement.
     */
    protected static int $navigationSort = 0;

    /**
     * The route base name (e.g. `products` → `products.index`). Defaults to the
     * pluralized, kebab-cased model basename, matching the generated routes.
     */
    protected static ?string $routeBaseName = null;

    /**
     * The record attribute used as a breadcrumb/title label. When null, falls
     * back to `name`, then `title`, then the model key.
     */
    protected static ?string $recordTitleAttribute = null;

    /**
     * Get the associated model class name.
     */
    public static function getModel(): string
    {
        if (static::$model === null) {
            throw new \RuntimeException('Static property $model must be set on '.static::class);
        }

        return static::$model;
    }

    /**
     * Get the default table instance for index page queries.
     */
    public static function table(Table $table): Table
    {
        return $table;
    }

    /**
     * Get the default form instance for edit/create views.
     */
    public static function form(Form $form): Form
    {
        return $form;
    }

    /**
     * Get the default infolist instance for the read-only view page.
     */
    public static function infolist(Infolist $infolist): Infolist
    {
        return $infolist;
    }

    /**
     * The base Eloquent query for this resource. Override to scope every read
     * and write to a tenant/team (e.g. `Model::where('team_id', ...)`). Used by
     * the generated index page and by the in-table modal endpoint to look up a
     * record, so a scoped query here keeps modal CRUD tenant-safe.
     */
    public static function getEloquentQuery(): Builder
    {
        return static::getModel()::query();
    }

    /**
     * Hook to mutate submitted form data before it is written, per operation
     * ('create' | 'edit'). Override to inject server-owned columns (e.g.
     * `$data['team_id'] = ...`) that are not part of the form schema.
     *
     * @param  array<string, mixed> $data
     * @return array<string, mixed>
     */
    public static function mutateFormDataBeforeSave(array $data, string $operation, ?Model $record = null): array
    {
        return $data;
    }

    /**
     * URL to redirect to after a record is created (full-page resources).
     * Defaults to the listing; override to keep the user on the record, e.g.
     * `return static::resolveHref('edit', $record);` (edit) or `'show'` (view).
     */
    public static function getRedirectUrlAfterCreate(Model $record): string
    {
        return static::resolveHref('index');
    }

    /**
     * URL to redirect to after a record is saved from the edit page (full-page
     * resources). Defaults to staying on the edit page; override to return
     * `static::resolveHref('index')` (list) or `'show'` (detail).
     */
    public static function getRedirectUrlAfterSave(Model $record): string
    {
        return static::resolveHref('edit', $record);
    }

    /**
     * The relation manager classes shown on the resource's edit/view page.
     *
     * @return array<int, class-string<RelationManager>>
     */
    public static function relationManagers(): array
    {
        return [];
    }

    /**
     * The relation manager classes that should appear on the given page
     * ('edit' | 'view'). With a parent `$record`, each manager's
     * `canViewForRecord()` decides (record/user-aware); without one it falls
     * back to the page-level `isVisibleOn()`.
     *
     * @return array<int, class-string<RelationManager>>
     */
    public static function relationManagersFor(string $page, ?Model $record = null): array
    {
        return array_values(array_filter(
            static::relationManagers(),
            static fn (string $relationManager): bool => $record !== null
                ? $relationManager::canViewForRecord($record, $page)
                : $relationManager::isVisibleOn($page),
        ));
    }

    /**
     * The permission feature name for this resource, or null to opt out of the
     * Kinetix permissions registry. Override to enable (e.g. return 'posts').
     */
    public static function permissionFeature(): ?string
    {
        return null;
    }

    /**
     * Register this resource's permissions on the registry. Defaults to a CRUD
     * feature when {@see permissionFeature()} is set; override for custom abilities.
     */
    public static function registerPermissions(PermissionRegistry $registry): void
    {
        $feature = static::permissionFeature();

        if ($feature === null) {
            return;
        }

        $registry->feature($feature)->crud();
    }

    /**
     * Get the navigation icon.
     */
    public static function getNavigationIcon(): ?string
    {
        return static::$navigationIcon;
    }

    /**
     * Get the navigation label.
     */
    public static function getNavigationLabel(): string
    {
        if (static::$navigationLabel !== null) {
            return static::$navigationLabel;
        }

        $modelClass = class_basename(static::getModel());

        return (string) str($modelClass)->plural()->headline();
    }

    /**
     * Get the navigation sort order.
     */
    public static function getNavigationSort(): int
    {
        return static::$navigationSort;
    }

    /**
     * The route base name used to build breadcrumb links (e.g. `products`).
     */
    public static function getRouteBaseName(): string
    {
        if (static::$routeBaseName !== null) {
            return static::$routeBaseName;
        }

        $modelClass = class_basename(static::getModel());

        return (string) str($modelClass)->plural()->kebab();
    }

    /**
     * A human label for a single record (breadcrumbs, page titles).
     */
    public static function getRecordTitle(Model $record): string
    {
        if (static::$recordTitleAttribute !== null) {
            return (string) $record->getAttribute(static::$recordTitleAttribute);
        }

        foreach (['name', 'title', 'label'] as $attribute) {
            $value = $record->getAttribute($attribute);

            if (is_string($value) && $value !== '') {
                return $value;
            }
        }

        return '#'.$record->getKey();
    }

    /**
     * Build the breadcrumb trail for a resource page, ready to feed the starter
     * kit's `<Breadcrumbs>` component (each item is `{ title, href }`). The last
     * item is the current page; its `href` is the current URL.
     *
     * @return array<int, array{title: string, href: string}>
     */
    public static function breadcrumbs(string $operation, ?Model $record = null): array
    {
        $index = [
            'title' => static::getNavigationLabel(),
            'href'  => static::resolveHref('index'),
        ];

        $here = static::currentUrl();

        return match ($operation) {
            'create' => [
                $index,
                ['title' => (string) __('kinetix.breadcrumb_create'), 'href' => $here],
            ],
            'edit' => $record !== null
                ? [
                    $index,
                    ['title' => static::getRecordTitle($record), 'href' => static::resolveHref('show', $record)],
                    ['title' => (string) __('kinetix.breadcrumb_edit'), 'href' => $here],
                ]
                : [
                    $index,
                    ['title' => (string) __('kinetix.breadcrumb_edit'), 'href' => $here],
                ],
            'show' => $record !== null
                ? [
                    $index,
                    ['title' => static::getRecordTitle($record), 'href' => $here],
                ]
                : [$index],
            default => [$index],
        };
    }

    /**
     * Public URL for a resource operation ('index' | 'create' | 'store' |
     * 'show' | 'edit' | 'update' | 'destroy'), auto-filling the record's route
     * key and the `{current_team}` segment when the route expects them. Use it
     * from controllers to hand ready-made URLs to the Vue pages, so the
     * frontend never rebuilds (team-scoped) routes itself.
     */
    public static function getUrl(string $operation, ?Model $record = null): string
    {
        return static::resolveHref($operation, $record);
    }

    /**
     * Resolve a route href for an operation, auto-filling required params (the
     * record + a `current_team` when the route expects one). Falls back to the
     * current URL when the route can't be built.
     */
    protected static function resolveHref(string $operation, ?Model $record = null): string
    {
        $name  = static::getRouteBaseName().'.'.$operation;
        $route = RouteFacade::getRoutes()->getByName($name);

        if ($route === null) {
            return static::currentUrl();
        }

        $params = [];

        foreach ($route->parameterNames() as $param) {
            if ($param === 'current_team') {
                $team = request()->route('current_team');

                if ($team !== null) {
                    $params[$param] = $team;
                }

                continue;
            }

            if ($record !== null) {
                $params[$param] = $record->getRouteKey();
            }
        }

        try {
            return route($name, $params);
        } catch (\Throwable) {
            return static::currentUrl();
        }
    }

    protected static function currentUrl(): string
    {
        return request()->fullUrl();
    }
}
