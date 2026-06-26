<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Happones\Kinetix\Tokens\KinetixTokens;
use Happones\Kinetix\Tokens\TokenScopeRegistry;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\HasApiTokens;

class TokenUser extends Authenticatable
{
    use HasApiTokens;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class TokensTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.tokens.enabled', true);
        $app['config']->set('kinetix.tokens.scopes', [
            'posts.read'  => 'Read posts',
            'posts.write' => 'Write posts',
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });
    }

    private function user(): TokenUser
    {
        return TokenUser::create(['name' => 'Dev']);
    }

    public function test_index_lists_own_tokens_with_scope_catalog_and_no_plaintext(): void
    {
        $user = $this->user();
        $user->createToken('CI', ['posts.read']);

        $response = $this->actingAs($user)->getJson('/_kinetix/tokens');

        $response->assertOk();
        $response->assertJsonCount(1, 'tokens');
        $response->assertJsonPath('tokens.0.name', 'CI');
        $response->assertJsonPath('tokens.0.abilities', ['posts.read']);
        $response->assertJsonPath('scopes', [
            'posts.read'  => 'Read posts',
            'posts.write' => 'Write posts',
        ]);
        $this->assertStringNotContainsString('plainText', $response->getContent());
    }

    public function test_store_creates_token_and_returns_plaintext_once(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user)->postJson('/_kinetix/tokens', [
            'name'      => 'Deploy',
            'abilities' => ['posts.read', 'posts.write'],
        ]);

        $response->assertCreated();
        $response->assertJsonPath('token.name', 'Deploy');
        $response->assertJsonPath('token.abilities', ['posts.read', 'posts.write']);
        $this->assertNotEmpty($response->json('plainTextToken'));
        $this->assertCount(1, $user->fresh()->tokens);
    }

    public function test_store_rejects_abilities_outside_the_registry(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/_kinetix/tokens', ['name' => 'Bad', 'abilities' => ['posts.delete']])
            ->assertStatus(422);
    }

    public function test_store_requires_at_least_one_scope_when_catalog_declared(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/_kinetix/tokens', ['name' => 'NoScopes', 'abilities' => []])
            ->assertStatus(422);
    }

    public function test_store_defaults_to_full_access_when_no_scopes_declared(): void
    {
        // Replace the seeded catalog with an empty one for this request.
        $this->app->instance(TokenScopeRegistry::class, new TokenScopeRegistry);

        $user = $this->user();

        $response = $this->actingAs($user)->postJson('/_kinetix/tokens', ['name' => 'Full']);

        $response->assertCreated();
        $response->assertJsonPath('token.abilities', ['*']);
    }

    public function test_destroy_revokes_only_callers_token(): void
    {
        $owner = $this->user();
        $other = $this->user();

        $ownerToken = $owner->createToken('mine')->accessToken;
        $otherToken = $other->createToken('theirs')->accessToken;

        $this->actingAs($owner)
            ->deleteJson("/_kinetix/tokens/{$ownerToken->getKey()}")
            ->assertOk();

        $this->assertCount(0, $owner->fresh()->tokens);

        // A caller cannot delete a token they don't own.
        $this->actingAs($owner)
            ->deleteJson("/_kinetix/tokens/{$otherToken->getKey()}")
            ->assertOk();
        $this->assertCount(1, $other->fresh()->tokens);
    }

    public function test_registry_accepts_runtime_scopes(): void
    {
        KinetixTokens::scopes(['billing.read' => 'Read billing']);

        $this->assertArrayHasKey('billing.read', app(TokenScopeRegistry::class)->all());
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/_kinetix/tokens')->assertStatus(401);
    }
}
