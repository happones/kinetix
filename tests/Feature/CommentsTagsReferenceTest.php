<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Comments\KinetixComments;
use Happones\Kinetix\Tags\HasKinetixTags;
use Happones\Kinetix\Tags\KinetixTags;
use Happones\Kinetix\Tests\Concerns\ResolvesTeamRoutes;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\PermissionServiceProvider;

class CtrTeam extends Model
{
    protected $table = 'ctr_teams';

    public $timestamps = false;

    protected $guarded = [];

    public function getRouteKeyName(): string
    {
        return 'slug';
    }
}

class CtrUser extends Authenticatable
{
    protected $table = 'ctr_users';

    public $timestamps = false;

    protected $guarded = [];

    public function currentTeam(): BelongsTo
    {
        return $this->belongsTo(CtrTeam::class, 'current_team_id');
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(CtrTeam::class, 'ctr_team_user', 'user_id', 'team_id');
    }
}

class CtrPost extends Model
{
    use HasKinetixTags;

    protected $table = 'ctr_posts';

    public $timestamps = false;

    protected $guarded = [];
}

/**
 * A permission-only policy: it answers "may this user see posts at all",
 * never "is this post in your team" — the shape that let a team-B record be
 * read and written from team A by type + id.
 */
class CtrPostPolicy
{
    public function view(CtrUser $user, CtrPost $post): bool
    {
        return true;
    }

    public function update(CtrUser $user, CtrPost $post): bool
    {
        return true;
    }
}

/**
 * Comments and tags name their record with a signed reference
 * (`KinetixComments::reference()` / `KinetixTags::reference()`). Under teams a
 * raw type + id is refused unless `require_reference` is false, and then only
 * with a policy.
 */
class CommentsTagsReferenceTest extends TestCase
{
    use ResolvesTeamRoutes;

    private CtrTeam $teamA;

    private CtrTeam $teamB;

    private CtrUser $user;

    /**
     * @param  Application              $app
     * @return array<int, class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), PermissionServiceProvider::class];
    }

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.teams', true);
        $app['config']->set('kinetix.comments.enabled', true);
        $app['config']->set('kinetix.tags.enabled', true);
        $app['config']->set('auth.providers.users.model', CtrUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('ctr_teams', function (Blueprint $table) {
            $table->increments('id');
            $table->string('slug');
        });
        Schema::create('ctr_users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->unsignedInteger('current_team_id');
        });
        Schema::create('ctr_team_user', function (Blueprint $table) {
            $table->unsignedInteger('team_id');
            $table->unsignedInteger('user_id');
        });
        Schema::create('ctr_posts', function (Blueprint $table) {
            $table->increments('id');
            $table->unsignedInteger('team_id');
        });
        Schema::create('kinetix_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('commentable_type');
            $table->unsignedBigInteger('commentable_id');
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->text('body');
            $table->timestamps();
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
        });

        $this->teamA = CtrTeam::create(['slug' => 'team-a']);
        $this->teamB = CtrTeam::create(['slug' => 'team-b']);

        $this->user = CtrUser::create(['name' => 'Ada', 'current_team_id' => $this->teamA->id]);
        $this->user->teams()->attach([$this->teamA->id, $this->teamB->id]);
        $this->actingAs($this->user);

        KinetixComments::for([CtrPost::class]);
        KinetixTags::for([CtrPost::class]);
    }

    /**
     * A reference minted on a page of the given team.
     */
    private function commentReference(CtrPost $post, string $team = 'team-a'): string
    {
        $this->withTeamSegment($team);

        return KinetixComments::reference($post);
    }

    private function tagReference(CtrPost $post, bool $editable = true, string $team = 'team-a'): string
    {
        $this->withTeamSegment($team);

        return KinetixTags::reference($post, $editable);
    }

    public function test_a_raw_type_and_id_is_refused_under_teams_even_with_a_permission_only_policy(): void
    {
        Gate::policy(CtrPost::class, CtrPostPolicy::class);
        $foreign = CtrPost::create(['team_id' => $this->teamB->id]);

        $this->getJson('/team-a/_kinetix/comments?'.http_build_query([
            'commentable_type' => CtrPost::class,
            'commentable_id'   => $foreign->id,
        ]))->assertForbidden();

        $this->postJson('/team-a/_kinetix/comments', [
            'commentable_type' => CtrPost::class,
            'commentable_id'   => $foreign->id,
            'body'             => 'planted',
        ])->assertForbidden();

        $this->postJson('/team-a/_kinetix/tags/sync', [
            'taggable_type' => CtrPost::class,
            'taggable_id'   => $foreign->id,
            'tags'          => ['planted'],
        ])->assertForbidden();

        $this->assertDatabaseCount('kinetix_comments', 0);
        $this->assertDatabaseCount('kinetix_taggables', 0);
    }

    public function test_the_legacy_path_under_teams_needs_a_policy(): void
    {
        config()->set('kinetix.comments.require_reference', false);
        config()->set('kinetix.tags.require_reference', false);
        $post = CtrPost::create(['team_id' => $this->teamA->id]);

        $this->postJson('/team-a/_kinetix/comments', [
            'commentable_type' => CtrPost::class,
            'commentable_id'   => $post->id,
            'body'             => 'Hello',
        ])->assertForbidden();

        $this->postJson('/team-a/_kinetix/tags/sync', [
            'taggable_type' => CtrPost::class,
            'taggable_id'   => $post->id,
            'tags'          => ['x'],
        ])->assertForbidden();

        Gate::policy(CtrPost::class, CtrPostPolicy::class);

        $this->postJson('/team-a/_kinetix/comments', [
            'commentable_type' => CtrPost::class,
            'commentable_id'   => $post->id,
            'body'             => 'Hello',
        ])->assertCreated();

        $this->postJson('/team-a/_kinetix/tags/sync', [
            'taggable_type' => CtrPost::class,
            'taggable_id'   => $post->id,
            'tags'          => ['x'],
        ])->assertOk();
    }

    public function test_a_reference_reads_and_writes_its_record(): void
    {
        $post      = CtrPost::create(['team_id' => $this->teamA->id]);
        $reference = $this->commentReference($post);

        $this->postJson('/team-a/_kinetix/comments', ['reference' => $reference, 'body' => 'First!'])
            ->assertCreated()
            ->assertJsonPath('comments.0.body', 'First!');

        $this->getJson('/team-a/_kinetix/comments?'.http_build_query(['reference' => $reference]))
            ->assertOk()
            ->assertJsonPath('comments.0.body', 'First!');

        $tags = $this->tagReference($post);

        $this->postJson('/team-a/_kinetix/tags/sync', ['reference' => $tags, 'tags' => ['Laravel']])
            ->assertOk()
            ->assertJsonPath('tags', ['Laravel']);

        $this->getJson('/team-a/_kinetix/tags?'.http_build_query(['reference' => $tags]))
            ->assertOk()
            ->assertJsonPath('tags', ['Laravel']);
    }

    public function test_a_reference_is_bound_to_its_record(): void
    {
        $post  = CtrPost::create(['team_id' => $this->teamA->id]);
        $other = CtrPost::create(['team_id' => $this->teamB->id]);

        $this->postJson('/team-a/_kinetix/comments', [
            'reference'        => $this->commentReference($post),
            'commentable_type' => CtrPost::class,
            'commentable_id'   => $other->id,
            'body'             => 'Mine',
        ])->assertCreated();

        $this->assertDatabaseHas('kinetix_comments', ['commentable_id' => $post->id, 'body' => 'Mine']);
        $this->assertDatabaseMissing('kinetix_comments', ['commentable_id' => $other->id]);
    }

    public function test_a_reference_minted_in_one_team_is_refused_in_another(): void
    {
        $post = CtrPost::create(['team_id' => $this->teamA->id]);

        $this->postJson('/team-b/_kinetix/comments', ['reference' => $this->commentReference($post), 'body' => 'x'])
            ->assertForbidden();

        $this->postJson('/team-b/_kinetix/tags/sync', ['reference' => $this->tagReference($post), 'tags' => ['x']])
            ->assertForbidden();
    }

    public function test_a_reference_minted_for_someone_else_is_refused(): void
    {
        $post      = CtrPost::create(['team_id' => $this->teamA->id]);
        $reference = $this->commentReference($post);

        $intruder = CtrUser::create(['name' => 'Eve', 'current_team_id' => $this->teamA->id]);
        $intruder->teams()->attach($this->teamA->id);

        $this->actingAs($intruder)
            ->postJson('/team-a/_kinetix/comments', ['reference' => $reference, 'body' => 'x'])
            ->assertForbidden();
    }

    public function test_a_read_only_tag_reference_cannot_sync(): void
    {
        $post      = CtrPost::create(['team_id' => $this->teamA->id]);
        $reference = $this->tagReference($post, editable: false);

        $this->getJson('/team-a/_kinetix/tags?'.http_build_query(['reference' => $reference]))->assertOk();

        $this->postJson('/team-a/_kinetix/tags/sync', ['reference' => $reference, 'tags' => ['x']])
            ->assertForbidden();

        $this->assertDatabaseCount('kinetix_taggables', 0);
    }

    public function test_a_comments_reference_is_refused_by_tags_and_back(): void
    {
        $post = CtrPost::create(['team_id' => $this->teamA->id]);

        $this->postJson('/team-a/_kinetix/tags/sync', ['reference' => $this->commentReference($post), 'tags' => ['x']])
            ->assertForbidden();

        $this->postJson('/team-a/_kinetix/comments', ['reference' => $this->tagReference($post), 'body' => 'x'])
            ->assertForbidden();
    }

    public function test_a_reference_still_honours_a_denying_policy(): void
    {
        Gate::policy(CtrPost::class, CtrDenyingPostPolicy::class);
        $post = CtrPost::create(['team_id' => $this->teamA->id]);

        $this->postJson('/team-a/_kinetix/comments', ['reference' => $this->commentReference($post), 'body' => 'x'])
            ->assertForbidden();

        $this->postJson('/team-a/_kinetix/tags/sync', ['reference' => $this->tagReference($post), 'tags' => ['x']])
            ->assertForbidden();
    }

    public function test_an_unreadable_reference_is_rejected(): void
    {
        $this->postJson('/team-a/_kinetix/comments', ['reference' => 'not-a-token', 'body' => 'x'])
            ->assertStatus(400);
    }
}

class CtrDenyingPostPolicy
{
    public function view(CtrUser $user, CtrPost $post): bool
    {
        return false;
    }

    public function update(CtrUser $user, CtrPost $post): bool
    {
        return false;
    }
}
