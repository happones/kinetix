<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Resources\RelationManager;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class RmAuthor extends Model
{
    protected $table = 'rm_authors';

    public $timestamps = false;

    protected $guarded = [];

    public function articles()
    {
        return $this->hasMany(RmArticle::class, 'author_id');
    }
}

class RmArticle extends Model
{
    protected $table = 'rm_articles';

    public $timestamps = false;

    protected $guarded = [];
}

class ArticlesRelationManager extends RelationManager
{
    protected static string $relationship = 'articles';

    public function table(Table $table): Table
    {
        return $table->columns([TextColumn::make('title')]);
    }

    public function getBadge(): int|string|null
    {
        return $this->getRelationshipQuery()->count();
    }
}

class HiddenForDraftsManager extends ArticlesRelationManager
{
    public static function canViewForRecord(Model $parent, string $page): bool
    {
        return parent::canViewForRecord($parent, $page)
            && $parent->getAttribute('published') === true;
    }
}

class ModalArticlesManager extends ArticlesRelationManager
{
    public function table(Table $table): Table
    {
        return parent::table($table)->recordModals(RmAuthorResource::class);
    }
}

class RmAuthorResource extends Resource
{
    protected static ?string $model = RmAuthor::class;

    public static function table(Table $table): Table
    {
        return $table;
    }

    public static function form(Form $form): Form
    {
        return $form;
    }

    public static function relationManagers(): array
    {
        return [ArticlesRelationManager::class, HiddenForDraftsManager::class];
    }
}

class RelationManagerTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('rm_authors', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->boolean('published')->default(false);
        });

        Schema::create('rm_articles', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('author_id');
            $table->string('title');
        });
    }

    public function test_the_table_query_is_constrained_to_the_parent(): void
    {
        $mine  = RmAuthor::create(['name' => 'Mine']);
        $other = RmAuthor::create(['name' => 'Other']);

        RmArticle::create(['author_id' => $mine->id, 'title' => 'A']);
        RmArticle::create(['author_id' => $other->id, 'title' => 'B']);

        $titles = ArticlesRelationManager::make($mine)
            ->getRelationshipQuery()
            ->pluck('title')
            ->all();

        $this->assertSame(['A'], $titles);
    }

    public function test_the_table_is_namespaced_by_the_relationship_prefix(): void
    {
        $parent = RmAuthor::create(['name' => 'Mine']);

        $data = ArticlesRelationManager::make($parent)->toData();

        // Two managers on one page must not clash on search/sort/page params.
        $this->assertSame('articles_', $data->table->queryPrefix);
    }

    public function test_the_badge_hook_flows_into_the_payload(): void
    {
        $parent = RmAuthor::create(['name' => 'Mine']);
        RmArticle::create(['author_id' => $parent->id, 'title' => 'A']);
        RmArticle::create(['author_id' => $parent->id, 'title' => 'B']);

        $data = ArticlesRelationManager::make($parent)->toData();

        $this->assertSame(2, $data->badge);
    }

    public function test_the_title_defaults_to_the_headlined_relationship(): void
    {
        $this->assertSame('Articles', ArticlesRelationManager::getTitle());
    }

    public function test_resolving_without_a_parent_throws(): void
    {
        $this->expectException(RuntimeException::class);

        ArticlesRelationManager::make()->getRelationshipQuery();
    }

    public function test_record_modals_are_rejected_inside_a_relation_manager(): void
    {
        $parent = RmAuthor::create(['name' => 'Mine']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('recordModals() is not supported inside a relation manager');

        ModalArticlesManager::make($parent)->toData();
    }

    public function test_relation_managers_for_filters_by_can_view_for_record(): void
    {
        $draft     = RmAuthor::create(['name' => 'Draft', 'published' => false]);
        $published = RmAuthor::create(['name' => 'Live', 'published' => true]);

        $this->assertSame(
            [ArticlesRelationManager::class],
            RmAuthorResource::relationManagersFor('edit', $draft),
        );

        $this->assertSame(
            [ArticlesRelationManager::class, HiddenForDraftsManager::class],
            RmAuthorResource::relationManagersFor('edit', $published),
        );

        // Without a record it falls back to page-level visibility (both).
        $this->assertCount(2, RmAuthorResource::relationManagersFor('edit'));
    }
}
