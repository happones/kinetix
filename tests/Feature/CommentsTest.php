<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Comments\Comment;
use Happones\Kinetix\Comments\CommentRegistry;
use Happones\Kinetix\Comments\KinetixComments;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;

class CommentUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class CommentablePost extends Model
{
    protected $table = 'posts';

    public $timestamps = false;

    protected $guarded = [];
}

class CommentsTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.comments.enabled', true);
        $app['config']->set('auth.providers.users.model', CommentUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });
        Schema::create('posts', function (Blueprint $table) {
            $table->increments('id');
            $table->string('title')->nullable();
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

        KinetixComments::for([CommentablePost::class]);
    }

    protected function tearDown(): void
    {
        app(CommentRegistry::class);
        parent::tearDown();
    }

    private function user(): CommentUser
    {
        return CommentUser::create(['name' => 'Ada']);
    }

    private function makePost(): CommentablePost
    {
        return CommentablePost::create(['title' => 'Hello']);
    }

    public function test_posts_and_lists_a_comment(): void
    {
        $user = $this->user();
        $post = $this->makePost();

        $this->actingAs($user)
            ->postJson('/_kinetix/comments', [
                'commentable_type' => CommentablePost::class,
                'commentable_id'   => $post->id,
                'body'             => 'First!',
            ])
            ->assertCreated()
            ->assertJsonPath('comments.0.body', 'First!')
            ->assertJsonPath('comments.0.authorName', 'Ada')
            ->assertJsonPath('comments.0.editable', true);

        $this->assertDatabaseHas('kinetix_comments', ['body' => 'First!', 'commentable_id' => $post->id]);
    }

    public function test_threads_replies_under_the_parent(): void
    {
        $user   = $this->user();
        $post   = $this->makePost();
        $parent = Comment::create([
            'user_id'        => $user->id, 'commentable_type' => CommentablePost::class,
            'commentable_id' => $post->id, 'body' => 'parent',
        ]);

        $this->actingAs($user)
            ->postJson('/_kinetix/comments', [
                'commentable_type' => CommentablePost::class,
                'commentable_id'   => $post->id,
                'body'             => 'a reply',
                'parent_id'        => $parent->id,
            ])
            ->assertCreated()
            ->assertJsonPath('comments.0.replies.0.body', 'a reply');
    }

    public function test_only_the_author_can_edit_or_delete(): void
    {
        $author  = $this->user();
        $other   = CommentUser::create(['name' => 'Bob']);
        $post    = $this->makePost();
        $comment = Comment::create([
            'user_id'        => $author->id, 'commentable_type' => CommentablePost::class,
            'commentable_id' => $post->id, 'body' => 'mine',
        ]);

        $this->actingAs($other)
            ->putJson("/_kinetix/comments/{$comment->id}", ['body' => 'hacked'])
            ->assertForbidden();

        $this->actingAs($author)
            ->putJson("/_kinetix/comments/{$comment->id}", ['body' => 'edited'])
            ->assertOk();

        $this->assertDatabaseHas('kinetix_comments', ['id' => $comment->id, 'body' => 'edited']);
    }

    public function test_delete_removes_the_comment_and_its_replies(): void
    {
        $user   = $this->user();
        $post   = $this->makePost();
        $parent = Comment::create([
            'user_id'        => $user->id, 'commentable_type' => CommentablePost::class,
            'commentable_id' => $post->id, 'body' => 'parent',
        ]);
        $reply = Comment::create([
            'user_id'        => $user->id, 'commentable_type' => CommentablePost::class,
            'commentable_id' => $post->id, 'parent_id' => $parent->id, 'body' => 'reply',
        ]);

        $this->actingAs($user)
            ->deleteJson("/_kinetix/comments/{$parent->id}")
            ->assertOk();

        $this->assertDatabaseMissing('kinetix_comments', ['id' => $parent->id]);
        $this->assertDatabaseMissing('kinetix_comments', ['id' => $reply->id]);
    }

    public function test_unregistered_commentable_type_is_rejected(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/_kinetix/comments', [
                'commentable_type' => 'App\\Models\\Secret',
                'commentable_id'   => 1,
                'body'             => 'sneaky',
            ])
            ->assertNotFound();
    }
}
