<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Resources\RelationManager;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;

class BothPagesRelationManager extends RelationManager
{
    protected static string $relationship = 'posts';

    public function table(Table $table): Table
    {
        return $table;
    }
}

class ViewOnlyRelationManager extends RelationManager
{
    protected static string $relationship = 'comments';

    protected static array $visibleOn = ['view'];

    public function table(Table $table): Table
    {
        return $table;
    }
}

class VisibilityResource extends Resource
{
    public static function relationManagers(): array
    {
        return [BothPagesRelationManager::class, ViewOnlyRelationManager::class];
    }
}

class RelationManagerVisibilityTest extends TestCase
{
    public function test_default_is_visible_on_both_pages(): void
    {
        $this->assertTrue(BothPagesRelationManager::isVisibleOn('edit'));
        $this->assertTrue(BothPagesRelationManager::isVisibleOn('view'));
    }

    public function test_can_restrict_to_a_single_page(): void
    {
        $this->assertFalse(ViewOnlyRelationManager::isVisibleOn('edit'));
        $this->assertTrue(ViewOnlyRelationManager::isVisibleOn('view'));
    }

    public function test_resource_filters_relation_managers_by_page(): void
    {
        $this->assertSame(
            [BothPagesRelationManager::class, ViewOnlyRelationManager::class],
            VisibilityResource::relationManagersFor('view'),
        );

        $this->assertSame(
            [BothPagesRelationManager::class],
            VisibilityResource::relationManagersFor('edit'),
        );
    }
}
