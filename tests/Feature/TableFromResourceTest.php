<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Actions\BulkAction;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Columns\TextInputColumn;
use Happones\Kinetix\Tables\Filters\SelectFilter;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;

class FromResourcePost extends Model
{
    protected $table = 'from_resource_posts';

    public $timestamps = false;

    protected $guarded = [];

    protected $casts = ['archived' => 'bool'];
}

/** @mixin FromResourcePost */
class FromResourcePostResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['id' => $this->id, 'headline' => strtoupper($this->title)];
    }
}

class ArchiveFromResourcePosts extends BulkAction
{
    public function handle(Collection $records): void
    {
        $records->each->update(['archived' => true]);
    }
}

class FromResourcePostsTable extends Table
{
    protected function buildColumns(): array
    {
        return [TextColumn::make('headline')];
    }
}

class TableFromResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('from_resource_posts', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->string('team');
            $table->string('status')->default('live');
            $table->boolean('archived')->default(false);
        });

        foreach (['Alpha', 'Bravo', 'Charlie', 'Delta'] as $i => $title) {
            FromResourcePost::create(['title' => $title, 'team' => 'a', 'status' => $i === 1 ? 'draft' : 'live']);
        }

        FromResourcePost::create(['title' => 'Other team', 'team' => 'b']);
    }

    private function table(): Table
    {
        return Table::make(FromResourcePost::class)
            ->columns([TextColumn::make('headline')->sortable(using: fn ($query, string $direction) => $query->orderBy('title', $direction)), TextColumn::make('title')->searchable()->hidden()])
            ->filters([SelectFilter::make('status')->options(['live' => 'Live', 'draft' => 'Draft'])])
            ->paginated([2, 10]);
    }

    public function test_it_renders_the_rows_and_pages_the_controller_fetched(): void
    {
        $posts = FromResourcePost::query()->where('team', 'a')->orderBy('id')->paginate(2);

        DB::enableQueryLog();

        $data = Table::fromResource(FromResourcePostResource::collection($posts))
            ->columns([TextColumn::make('headline'), TextColumn::make('title')])
            ->toData();

        // The rows came from the collection: the table ran no query for them.
        $this->assertSame([], array_filter(
            array_column(DB::getQueryLog(), 'query'),
            static fn (string $sql): bool => str_contains($sql, 'from_resource_posts'),
        ));

        $this->assertSame([1, 2], array_map(static fn ($row) => $row->id, $data->records));
        $this->assertSame('ALPHA', $data->records[0]->values['headline']);
        // Each row reads its own resource, so what it leaves out is empty.
        $this->assertNull($data->records[0]->values['title']);
        $this->assertSame(4, $data->pagination->total);
        $this->assertSame(2, $data->pagination->lastPage);
    }

    public function test_apply_to_and_paginate_give_the_controller_the_tables_state(): void
    {
        request()->merge(['posts_search' => 'a', 'posts_sort' => 'headline', 'posts_direction' => 'desc', 'posts_filters' => ['status' => 'live'], 'posts_perPage' => 2, 'posts_page' => 1]);

        $table = $this->table()->queryPrefix('posts_');
        $posts = $table->paginate($table->applyTo(FromResourcePost::query()->where('team', 'a')));

        // Live posts whose title has an "a", newest title first, two a page.
        $this->assertSame(['Delta', 'Charlie'], collect($posts->items())->pluck('title')->all());
        $this->assertSame(3, $posts->total());
        $this->assertSame('posts_page', $posts->getPageName());
    }

    public function test_a_plain_collection_has_no_pager(): void
    {
        $data = Table::fromResource(FromResourcePostResource::collection(FromResourcePost::query()->limit(2)->get()))
            ->columns([TextColumn::make('headline')])
            ->toData();

        $this->assertCount(2, $data->records);
        $this->assertNull($data->pagination);
    }

    public function test_an_empty_collection_names_its_model(): void
    {
        $data = Table::fromResource(FromResourcePostResource::collection(collect()), FromResourcePost::class)
            ->columns([TextColumn::make('headline')])
            ->toData();

        $this->assertSame([], $data->records);

        $this->expectException(InvalidArgumentException::class);

        Table::fromResource(FromResourcePostResource::collection(collect()));
    }

    /**
     * Without a query, the write endpoints would resolve ids against the
     * whole model: any post, any team.
     */
    public function test_kinetix_write_endpoints_need_a_scope(): void
    {
        $collection = FromResourcePostResource::collection(FromResourcePost::query()->where('team', 'a')->get());

        // Actions on your own routes need none.
        Table::fromResource($collection)
            ->columns([TextColumn::make('headline')])
            ->recordActions([Action::make('edit')->url(fn (FromResourcePost $post): string => "/posts/{$post->id}/edit")])
            ->toData();

        try {
            Table::fromResource($collection)
                ->columns([TextInputColumn::make('title')])
                ->bulkActions([ArchiveFromResourcePosts::make()])
                ->toData();

            $this->fail('No scope, yet the endpoints were sealed.');
        } catch (LogicException $e) {
            $this->assertStringContainsString('inline edits, server-side bulk actions', $e->getMessage());
            $this->assertStringContainsString('->scopedBy(', $e->getMessage());
        }
    }

    public function test_a_scoped_table_bounds_its_endpoints(): void
    {
        $data = Table::fromResource(FromResourcePostResource::collection(FromResourcePost::query()->where('team', 'a')->get()))
            ->scopedBy(FromResourcePost::query()->where('team', 'a'))
            ->columns([TextColumn::make('headline')])
            ->bulkActions([ArchiveFromResourcePosts::make()])
            ->toData();

        $this->assertSame(['team' => 'a'], Crypt::decrypt($data->bulkDescriptor)['scope']);

        // Post 5 belongs to the other team: dropped, never acted on.
        $this->postJson(route('kinetix.tables.bulk-action'), [
            'descriptor' => $data->bulkDescriptor,
            'action'     => 'archive-from-resource-posts',
            'ids'        => [1, 5],
        ])->assertOk()->assertJsonPath('affected', 1);

        $this->assertSame([1 => true, 5 => false], FromResourcePost::query()->whereKey([1, 5])->pluck('archived', 'id')->all());
    }

    public function test_a_table_class_keeps_its_columns(): void
    {
        $data = FromResourcePostsTable::fromResource(FromResourcePostResource::collection(FromResourcePost::query()->limit(1)->get()))->toData();

        $this->assertInstanceOf(FromResourcePostsTable::class, FromResourcePostsTable::fromResource(FromResourcePostResource::collection(collect()), FromResourcePost::class));
        $this->assertSame(['headline' => 'ALPHA'], $data->records[0]->values);
    }
}
