<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\CheckboxList;
use Happones\Kinetix\Forms\Components\Radio;
use Happones\Kinetix\Forms\Components\Select;
use Happones\Kinetix\Forms\Form;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

class RelAuthor extends Model
{
    protected $table = 'authors';

    public $timestamps = false;

    protected $guarded = [];
}

class RelArticle extends Model
{
    protected $table = 'articles';

    public $timestamps = false;

    protected $guarded = [];

    /**
     * @return BelongsTo<RelAuthor, $this>
     */
    public function author(): BelongsTo
    {
        return $this->belongsTo(RelAuthor::class, 'author_id');
    }
}

/** Serializable query modifier — the only form that survives into a search token. */
class ActiveAuthorsOnly
{
    public function __invoke(mixed $query): void
    {
        $query->where('active', true);
    }
}

/**
 * `relationship()` lets a Select name the relation instead of repeating the
 * related class and its columns. Inherited by CheckboxList and Radio, mirroring
 * the API SelectFilter already exposed.
 */
class SelectRelationshipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('authors', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name');
            $table->boolean('active')->default(true);
        });

        Schema::create('articles', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('author_id')->nullable();
        });

        RelAuthor::create(['id' => 1, 'name' => 'Ada', 'active' => true]);
        RelAuthor::create(['id' => 2, 'name' => 'Grace', 'active' => true]);
        RelAuthor::create(['id' => 3, 'name' => 'Retired', 'active' => false]);
    }

    /**
     * @param  array<int, mixed>    $fields
     * @return array<string, mixed>
     */
    private function render(array $fields): array
    {
        $schema = Form::make()
            ->model(RelArticle::class)
            ->schema($fields)
            ->toArray();

        return $schema['schema'][0];
    }

    public function test_it_derives_the_options_from_the_relation(): void
    {
        $field = $this->render([
            Select::make('author_id')->relationship('author', 'name'),
        ]);

        $this->assertSame(
            ['1' => 'Ada', '2' => 'Grace', '3' => 'Retired'],
            $field['options'],
        );
    }

    public function test_a_closure_narrows_the_options(): void
    {
        $field = $this->render([
            Select::make('author_id')->relationship('author', 'name', fn ($q) => $q->where('active', true)),
        ]);

        $this->assertSame(['1' => 'Ada', '2' => 'Grace'], $field['options']);
    }

    public function test_an_invokable_class_string_narrows_them_too(): void
    {
        $field = $this->render([
            Select::make('author_id')->relationship('author', 'name', ActiveAuthorsOnly::class),
        ]);

        $this->assertSame(['1' => 'Ada', '2' => 'Grace'], $field['options']);
    }

    public function test_checkbox_list_and_radio_inherit_it(): void
    {
        foreach ([CheckboxList::class, Radio::class] as $component) {
            $field = $this->render([
                $component::make('author_id')->relationship('author', 'name'),
            ]);

            $this->assertSame(
                ['1' => 'Ada', '2' => 'Grace', '3' => 'Retired'],
                $field['options'],
                $component.' should inherit relationship().',
            );
        }
    }

    public function test_without_an_owning_model_it_degrades_instead_of_throwing(): void
    {
        // No Form::model() and no record to infer one from.
        $schema = Form::make()
            ->schema([Select::make('author_id')->relationship('author', 'name')])
            ->toArray();

        $this->assertSame([], $schema['schema'][0]['options']);
    }

    public function test_an_unknown_relation_name_does_not_throw(): void
    {
        $field = $this->render([
            Select::make('author_id')->relationship('nope', 'name'),
        ]);

        $this->assertSame([], $field['options']);
    }

    public function test_the_options_are_capped(): void
    {
        config()->set('kinetix.forms.relationship_options_limit', 2);

        $field = $this->render([
            Select::make('author_id')->relationship('author', 'name'),
        ]);

        $this->assertCount(2, $field['options']);
    }

    public function test_a_searchable_relationship_builds_the_token_from_the_relation(): void
    {
        $field = $this->render([
            Select::make('author_id')->relationship('author', 'name')->searchable(),
        ]);

        $this->assertTrue($field['isSearchable']);

        $descriptor = Crypt::decrypt($field['searchToken']);

        $this->assertSame(RelAuthor::class, $descriptor['model']);
        $this->assertSame('name', $descriptor['label']);
        $this->assertSame(['name'], $descriptor['columns']);
        $this->assertSame('id', $descriptor['value']);
    }

    public function test_the_token_carries_a_serializable_modifier_only(): void
    {
        $withClass = $this->render([
            Select::make('author_id')
                ->relationship('author', 'name', ActiveAuthorsOnly::class)
                ->searchable(),
        ]);

        $this->assertSame(
            ActiveAuthorsOnly::class,
            Crypt::decrypt($withClass['searchToken'])['modifier'],
        );

        // A closure cannot be serialized into a token, so it is simply absent —
        // it still shapes the eagerly loaded options.
        $withClosure = $this->render([
            Select::make('author_id')
                ->relationship('author', 'name', fn ($q) => $q->where('active', true))
                ->searchable(),
        ]);

        $this->assertNull(Crypt::decrypt($withClosure['searchToken'])['modifier']);
    }

    public function test_search_using_still_wins_when_both_are_declared(): void
    {
        $field = $this->render([
            Select::make('author_id')
                ->relationship('author', 'name')
                ->searchUsing(RelAuthor::class, 'name', ['name'], 'id'),
        ]);

        $descriptor = Crypt::decrypt($field['searchToken']);

        $this->assertSame(RelAuthor::class, $descriptor['model']);
        $this->assertArrayNotHasKey('modifier', $descriptor);
    }

    public function test_a_non_searchable_relationship_ships_no_token(): void
    {
        $field = $this->render([
            Select::make('author_id')->relationship('author', 'name'),
        ]);

        $this->assertNull($field['searchToken'] ?? null);
    }

    public function test_the_search_endpoint_applies_the_token_modifier(): void
    {
        $token = Crypt::encrypt([
            'model'    => RelAuthor::class,
            'label'    => 'name',
            'columns'  => ['name'],
            'value'    => 'id',
            'modifier' => ActiveAuthorsOnly::class,
        ]);

        $options = $this->postJson('/_kinetix/forms/search', ['token' => $token])
            ->assertOk()
            ->json('options');

        $this->assertSame(['1' => 'Ada', '2' => 'Grace'], $options);
    }
}
