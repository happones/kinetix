<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Actions\Action;
use Happones\Kinetix\Tables\ApiResourceRow;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Table;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Schema;
use InvalidArgumentException;
use LogicException;

class ApiAuthor extends Model
{
    protected $table = 'api_authors';

    public $timestamps = false;

    protected $guarded = [];
}

class ApiPost extends Model
{
    protected $table = 'api_posts';

    public $timestamps = false;

    protected $guarded = [];

    /** @return BelongsTo<ApiAuthor, $this> */
    public function author(): BelongsTo
    {
        return $this->belongsTo(ApiAuthor::class, 'author_id');
    }
}

/** @mixin ApiAuthor */
class ApiAuthorResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return ['name' => $this->name];
    }
}

/** @mixin ApiPost */
class ApiPostResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'id'       => $this->id,
            'title'    => $this->title,
            'headline' => strtoupper($this->title),
            'author'   => new ApiAuthorResource($this->whenLoaded('author')),
            'draft'    => $this->when($this->status === 'draft', true),
        ];
    }
}

class TableApiResourceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('api_authors', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('name');
            $table->string('email');
        });

        Schema::create('api_posts', function (Blueprint $table): void {
            $table->increments('id');
            $table->string('title');
            $table->string('status');
            $table->string('secret');
            $table->unsignedInteger('author_id');
        });

        $ada = ApiAuthor::create(['name' => 'Ada', 'email' => 'ada@example.com']);
        $bob = ApiAuthor::create(['name' => 'Bob', 'email' => 'bob@example.com']);

        ApiPost::create(['title' => 'First', 'status' => 'draft', 'secret' => 's1', 'author_id' => $ada->id]);
        ApiPost::create(['title' => 'Second', 'status' => 'live', 'secret' => 's2', 'author_id' => $bob->id]);
    }

    /**
     * @return array<int|string, array<string, mixed>> values by row key
     */
    private function rows(Table $table): array
    {
        $rows = [];

        foreach ($table->toData()->records as $record) {
            $rows[$record->id] = $record->values;
        }

        return $rows;
    }

    private function table(): Table
    {
        return Table::make(ApiPost::query())
            ->apiResource(ApiPostResource::class)
            ->columns([
                TextColumn::make('headline'),
                TextColumn::make('author.name'),
                TextColumn::make('author.email'),
                TextColumn::make('secret'),
                TextColumn::make('draft'),
            ]);
    }

    public function test_columns_read_what_the_resource_returns(): void
    {
        $rows = $this->rows($this->table());

        $this->assertSame('FIRST', $rows[1]['headline']);
        // The nested resource resolves through its own toArray(), and the
        // relation a dot column names is eager-loaded for whenLoaded().
        $this->assertSame('Ada', $rows[1]['author.name']);
        $this->assertTrue($rows[1]['draft']);
    }

    public function test_what_the_resource_leaves_out_stays_out(): void
    {
        $rows = $this->rows($this->table());

        // Attributes the model has but the resources don't return.
        $this->assertNull($rows[1]['secret']);
        $this->assertNull($rows[1]['author.email']);
        // A when() that doesn't hold.
        $this->assertNull($rows[2]['draft']);
    }

    public function test_the_table_still_queries_the_model_and_actions_get_it(): void
    {
        request()->merge(['search' => 'Sec']);
        $seen = [];

        $data = Table::make(ApiPost::query())
            ->apiResource(ApiPostResource::class)
            ->columns([TextColumn::make('title')->searchable()])
            ->recordActions([
                Action::make('edit')->url(function (ApiPost $post) use (&$seen): string {
                    $seen[] = $post->secret;

                    return '/posts/'.$post->id.'/edit';
                }),
            ])
            ->toData();

        $this->assertSame([2], array_map(static fn ($record) => $record->id, $data->records));
        $this->assertSame(['s2'], $seen);
        $this->assertSame('/posts/2/edit', $data->records[0]->actions[0]->url);
    }

    public function test_a_closure_builds_the_resource(): void
    {
        $rows = $this->rows(
            Table::make(ApiPost::query())
                ->apiResource(fn (ApiPost $post): JsonResource => new ApiPostResource($post))
                ->columns([TextColumn::make('headline')]),
        );

        $this->assertSame('SECOND', $rows[2]['headline']);
    }

    public function test_the_row_is_forgotten_once_built(): void
    {
        $seen = [];

        Table::make(ApiPost::query())
            ->apiResource(ApiPostResource::class)
            ->transformRecordsUsing(function (ApiPost $post) use (&$seen): ApiPost {
                $seen[] = $post;

                return $post;
            })
            ->columns([TextColumn::make('headline')])
            ->toData();

        $this->assertCount(2, $seen);
        $this->assertNull(ApiResourceRow::of($seen[0]));
    }

    public function test_only_an_api_resource_is_accepted(): void
    {
        $this->expectException(InvalidArgumentException::class);

        Table::make(ApiPost::query())->apiResource(ApiPost::class);
    }

    public function test_a_closure_must_return_a_resource(): void
    {
        $this->expectException(LogicException::class);

        Table::make(ApiPost::query())
            ->apiResource(fn (ApiPost $post): array => ['title' => $post->title])
            ->columns([TextColumn::make('title')])
            ->toData();
    }
}
