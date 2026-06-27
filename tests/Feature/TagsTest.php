<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tables\Filters\TagFilter;
use Happones\Kinetix\Tags\HasKinetixTags;
use Happones\Kinetix\Tags\KinetixTags;
use Happones\Kinetix\Tags\Tag;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;

class TagUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class TaggableArticle extends Model
{
    use HasKinetixTags;

    protected $table = 'articles';

    public $timestamps = false;

    protected $guarded = [];
}

class TagsTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.tags.enabled', true);
        $app['config']->set('auth.providers.users.model', TagUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
        Schema::create('articles', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title')->nullable();
        });
        Schema::create('kinetix_tags', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('name');
            $table->string('slug');
            $table->timestamps();
            $table->unique(['team_id', 'slug']);
        });
        Schema::create('kinetix_taggables', function (Blueprint $table) {
            $table->unsignedBigInteger('tag_id');
            $table->string('taggable_type');
            $table->unsignedBigInteger('taggable_id');
            $table->unique(['tag_id', 'taggable_type', 'taggable_id'], 'kinetix_taggables_unique');
        });

        KinetixTags::for([TaggableArticle::class]);
    }

    private function user(): TagUser
    {
        return TagUser::create(['name' => 'Ada']);
    }

    private function makeArticle(): TaggableArticle
    {
        return TaggableArticle::create(['title' => 'Hello']);
    }

    public function test_sync_finds_or_creates_tags_and_attaches_them(): void
    {
        $user    = $this->user();
        $article = $this->makeArticle();

        $this->actingAs($user)
            ->postJson('/_kinetix/tags/sync', [
                'taggable_type' => TaggableArticle::class,
                'taggable_id'   => $article->id,
                'tags'          => ['Laravel', 'Vue', 'Laravel'], // dedup
            ])
            ->assertOk()
            ->assertJsonPath('tags', ['Laravel', 'Vue']);

        $this->assertSame(2, Tag::count());
        $this->assertSame(2, $article->tags()->count());
    }

    public function test_sync_reuses_existing_tags_by_slug(): void
    {
        $user = $this->user();
        $a    = $this->makeArticle();
        $b    = $this->makeArticle();

        $this->actingAs($user)->postJson('/_kinetix/tags/sync', [
            'taggable_type' => TaggableArticle::class, 'taggable_id' => $a->id, 'tags' => ['News'],
        ])->assertOk();

        $this->actingAs($user)->postJson('/_kinetix/tags/sync', [
            'taggable_type' => TaggableArticle::class, 'taggable_id' => $b->id, 'tags' => ['news'],
        ])->assertOk();

        // Same slug → one shared tag.
        $this->assertSame(1, Tag::count());
    }

    public function test_suggest_returns_matching_tag_names(): void
    {
        $user = $this->user();
        Tag::create(['name' => 'Laravel', 'slug' => 'laravel']);
        Tag::create(['name' => 'Livewire', 'slug' => 'livewire']);
        Tag::create(['name' => 'Vue', 'slug' => 'vue']);

        $this->actingAs($user)
            ->getJson('/_kinetix/tags/suggest?q=li')
            ->assertOk()
            ->assertJsonPath('tags', ['Livewire']);
    }

    public function test_index_lists_a_models_tags(): void
    {
        $user    = $this->user();
        $article = $this->makeArticle();
        $tag     = Tag::create(['name' => 'Featured', 'slug' => 'featured']);
        $article->tags()->attach($tag->id);

        $this->actingAs($user)
            ->getJson('/_kinetix/tags?taggable_type='.urlencode(TaggableArticle::class).'&taggable_id='.$article->id)
            ->assertOk()
            ->assertJsonPath('tags', ['Featured']);
    }

    public function test_unregistered_type_is_rejected(): void
    {
        $this->actingAs($this->user())
            ->postJson('/_kinetix/tags/sync', [
                'taggable_type' => 'App\\Models\\Secret', 'taggable_id' => 1, 'tags' => ['x'],
            ])
            ->assertNotFound();
    }

    public function test_tag_filter_matches_records_with_a_tag(): void
    {
        $a   = $this->makeArticle();
        $b   = $this->makeArticle();
        $tag = Tag::create(['name' => 'Featured', 'slug' => 'featured']);
        $a->tags()->attach($tag->id);

        $q = TaggableArticle::query();
        TagFilter::make('tags')->apply($q, ['Featured']);

        $this->assertSame([$a->id], $q->pluck('id')->all());
    }
}
