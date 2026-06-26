<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\Select;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Schema;

class SearchAuthor extends Model
{
    protected $table = 'authors';

    public $timestamps = false;

    protected $guarded = [];
}

class SearchPost extends Model
{
    protected $table = 'posts';

    public $timestamps = false;

    protected $guarded = [];
}

class SelectSearchableTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('authors', function ($table) {
            $table->increments('id');
            $table->string('name');
            $table->string('email')->nullable();
        });

        Schema::create('posts', function ($table) {
            $table->increments('id');
            $table->unsignedInteger('author_id')->nullable();
        });
    }

    public function test_searchable_flag_is_serialized(): void
    {
        $data = Select::make('country')->searchable()->options(['mx' => 'Mexico'])->toData('create');

        $this->assertTrue($data->isSearchable);
        $this->assertNull($data->searchToken);
    }

    public function test_search_using_encrypts_a_descriptor_token(): void
    {
        $data = Select::make('author_id')
            ->searchUsing(SearchAuthor::class, 'name', ['name', 'email'])
            ->toData('create');

        $this->assertTrue($data->isSearchable);
        $this->assertNotNull($data->searchToken);

        $descriptor = Crypt::decrypt($data->searchToken);
        $this->assertSame(SearchAuthor::class, $descriptor['model']);
        $this->assertSame('name', $descriptor['label']);
        $this->assertSame(['name', 'email'], $descriptor['columns']);
    }

    public function test_remote_select_ships_only_the_selected_option_label(): void
    {
        SearchAuthor::create(['id' => 1, 'name' => 'Ada Lovelace']);
        $post = SearchPost::create(['id' => 1, 'author_id' => 1]);

        $data = Select::make('author_id')
            ->searchUsing(SearchAuthor::class, 'name')
            ->toData('edit', $post);

        $this->assertSame(['1' => 'Ada Lovelace'], $data->options);
    }

    public function test_search_endpoint_filters_by_the_declared_columns(): void
    {
        SearchAuthor::create(['name' => 'Ada Lovelace', 'email' => 'ada@x.test']);
        SearchAuthor::create(['name' => 'Alan Turing', 'email' => 'alan@x.test']);

        $token = Crypt::encrypt([
            'model'   => SearchAuthor::class,
            'label'   => 'name',
            'columns' => ['name'],
            'value'   => 'id',
        ]);

        $this->postJson('/_kinetix/forms/search', ['token' => $token, 'q' => 'ada'])
            ->assertOk()
            ->assertJsonFragment(['options' => ['1' => 'Ada Lovelace']]);
    }

    public function test_search_endpoint_rejects_an_invalid_token(): void
    {
        $this->postJson('/_kinetix/forms/search', ['token' => 'tampered', 'q' => 'x'])
            ->assertStatus(400);
    }
}
