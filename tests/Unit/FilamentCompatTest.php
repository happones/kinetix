<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Unit;

use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Tables\Columns\TextColumn;
use Happones\Kinetix\Tables\Filters\MultiSelectFilter;
use Happones\Kinetix\Tables\Filters\SelectFilter;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CompatAuthor extends Model
{
    protected $table = 'compat_authors';

    public $timestamps = false;

    protected $guarded = [];
}

class CompatPost extends Model
{
    protected $table = 'compat_posts';

    public $timestamps = false;

    protected $guarded = [];

    public function author(): BelongsTo
    {
        return $this->belongsTo(CompatAuthor::class, 'author_id');
    }
}

/**
 * Filament-compatible API sugar: Column::state(), SelectFilter::relationship()
 * and Component::columnSpanFull().
 */
class FilamentCompatTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('compat_authors', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->boolean('active')->default(true);
        });
        Schema::create('compat_posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title');
            $table->unsignedInteger('author_id');
            $table->integer('likes')->default(0);
        });

        $ada   = CompatAuthor::create(['name' => 'Ada']);
        $grace = CompatAuthor::create(['name' => 'Grace', 'active' => false]);

        CompatPost::create(['title' => 'P1', 'author_id' => $ada->id, 'likes' => 5]);
        CompatPost::create(['title' => 'P2', 'author_id' => $grace->id, 'likes' => 9]);
    }

    public function test_column_state_derives_the_value_from_a_closure(): void
    {
        $column = TextColumn::make('summary')
            ->state(fn (CompatPost $post): string => "{$post->title} ({$post->likes})");

        $this->assertSame('P1 (5)', $column->getState(CompatPost::where('title', 'P1')->firstOrFail()));
    }

    public function test_column_state_composes_with_format_state_using(): void
    {
        $column = TextColumn::make('likes_label')
            ->state(fn (CompatPost $post): int => $post->likes)
            ->formatStateUsing(fn (int $state): string => "{$state} likes");

        $this->assertSame('9 likes', $column->getState(CompatPost::where('title', 'P2')->firstOrFail()));
    }

    public function test_get_state_using_is_an_alias_and_constants_are_supported(): void
    {
        $post = CompatPost::where('title', 'P1')->firstOrFail();

        $aliased = TextColumn::make('x')->getStateUsing(fn (): string => 'via alias');
        $this->assertSame('via alias', $aliased->getState($post));

        $constant = TextColumn::make('y')->state('fixed');
        $this->assertSame('fixed', $constant->getState($post));
    }

    public function test_select_filter_relationship_builds_options_from_the_related_model(): void
    {
        $filter = SelectFilter::make('author')
            ->relationship('author', 'name')
            ->forModel(CompatPost::class);

        $this->assertSame(['1' => 'Ada', '2' => 'Grace'], $filter->getOptions());
    }

    public function test_select_filter_relationship_options_honor_the_query_modifier(): void
    {
        $filter = SelectFilter::make('author')
            ->relationship('author', 'name', fn ($query) => $query->where('active', true))
            ->forModel(CompatPost::class);

        $this->assertSame(['1' => 'Ada'], $filter->getOptions());
    }

    public function test_select_filter_relationship_applies_a_where_has(): void
    {
        $query = CompatPost::query();
        SelectFilter::make('author')->relationship('author', 'name')->apply($query, 2);

        $this->assertSame(['P2'], $query->pluck('title')->all());
    }

    public function test_multi_select_filter_relationship_applies_where_has_over_all_values(): void
    {
        $query = CompatPost::query();
        MultiSelectFilter::make('author')->relationship('author', 'name')->apply($query, [1, 2]);

        $this->assertSame(['P1', 'P2'], $query->orderBy('title')->pluck('title')->all());
    }

    public function test_column_span_full_serializes_as_full(): void
    {
        $data = TextInput::make('bio')->columnSpanFull()->toData('create', null);

        $this->assertSame('full', $data->columnSpan);
    }
}
