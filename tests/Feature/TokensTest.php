<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Happones\Kinetix\Tokens\KinetixTokens;
use Happones\Kinetix\Tokens\TokenScopeRegistry;
use Illuminate\Database\Eloquent\Model;
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

/** A user whose current team is whatever the test sets. */
class TeamTokenUser extends TokenUser
{
    public ?Model $team = null;

    public function getCurrentTeamAttribute(): ?Model
    {
        return $this->team;
    }
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
            $table->unsignedBigInteger('team_id')->nullable();
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

    public function test_store_accepts_an_optional_expiration_date(): void
    {
        $user = $this->user();

        $response = $this->actingAs($user)->postJson('/_kinetix/tokens', [
            'name'       => 'Expiring',
            'abilities'  => ['posts.read'],
            'expires_at' => now()->addDays(30)->format('Y-m-d'),
        ]);

        $response->assertCreated();

        // Persisted at the end of the chosen day, and serialized in the DTO.
        $token = $user->fresh()->tokens->first();
        $this->assertSame(
            now()->addDays(30)->endOfDay()->toDateTimeString(),
            $token->expires_at->toDateTimeString(),
        );
        $this->assertNotNull($response->json('token.expiresAt'));
    }

    public function test_store_rejects_a_past_expiration_date(): void
    {
        $this->actingAs($this->user())->postJson('/_kinetix/tokens', [
            'name'       => 'Stale',
            'abilities'  => ['posts.read'],
            'expires_at' => now()->subDay()->format('Y-m-d'),
        ])->assertStatus(422);
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

    public function test_index_names_the_default_scopes_that_are_in_the_catalog(): void
    {
        config(['kinetix.tokens.default_scopes' => ['posts.read', 'comments.read']]);
        $this->app->forgetInstance(TokenScopeRegistry::class);

        $this->actingAs($this->user())->getJson('/_kinetix/tokens')
            ->assertOk()
            ->assertJsonPath('defaultScopes', ['posts.read']);
    }

    public function test_default_scopes_can_be_declared_at_runtime(): void
    {
        KinetixTokens::defaultScopes(['posts.write']);

        $this->assertSame(['posts.write'], app(TokenScopeRegistry::class)->defaultKeys());
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

    public function test_with_a_team_column_the_list_and_revocation_stay_inside_the_current_team(): void
    {
        config()->set('kinetix.tokens.team_column', 'team_id');

        $user       = TeamTokenUser::create(['name' => 'Dev']);
        $user->team = (new class extends Model {})->forceFill(['id' => 7]);

        $ours   = $user->createToken('ours', ['posts.read'])->accessToken;
        $legacy = $user->createToken('legacy', ['posts.read'])->accessToken;
        $theirs = $user->createToken('theirs', ['posts.read'])->accessToken;
        $ours->forceFill(['team_id' => 7])->save();
        $theirs->forceFill(['team_id' => 9])->save();

        $names = collect($this->actingAs($user)->getJson('/_kinetix/tokens')->json('tokens'))->pluck('name');

        $this->assertEqualsCanonicalizing(['ours', 'legacy'], $names->all());

        // Another team's token cannot be revoked from this one.
        $this->actingAs($user)->deleteJson('/_kinetix/tokens/'.$theirs->getKey())->assertOk();
        $this->assertNotNull($theirs->fresh());

        // A new token is stamped with the current team.
        $created = $this->actingAs($user)->postJson('/_kinetix/tokens', ['name' => 'new', 'abilities' => ['posts.read']]);
        $this->assertSame(7, (int) $user->tokens()->whereKey($created->json('token.id'))->value('team_id'));
    }
}
