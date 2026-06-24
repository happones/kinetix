<?php

declare(strict_types=1);

namespace Happones\Kinetix\Resources;

use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Infolists\Infolist;
use Happones\Kinetix\Permissions\PermissionRegistry;
use Happones\Kinetix\Tables\Table;

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
     * ('edit' | 'view'), filtered by each manager's `isVisibleOn()`.
     *
     * @return array<int, class-string<RelationManager>>
     */
    public static function relationManagersFor(string $page): array
    {
        return array_values(array_filter(
            static::relationManagers(),
            static fn (string $relationManager): bool => $relationManager::isVisibleOn($page),
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
}
