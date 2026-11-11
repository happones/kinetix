<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;

/**
 * `tokens.create_middleware` guards minting only: a team that lost the plan
 * can still list and revoke its tokens.
 */
class TokensCreateMiddlewareTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.tokens.enabled', true);
        $app['config']->set('kinetix.tokens.create_middleware', ['can:mint-tokens']);
        $app['config']->set('kinetix.tokens.team_ability', 'team:');
        $app['config']->set('kinetix.tokens.stamp_abilities', ['integration']);
    }

    public function test_the_create_middleware_applies_to_the_store_route_only(): void
    {
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

        Gate::define('mint-tokens', fn (): bool => false);

        $user  = TokenUser::create(['name' => 'Dev']);
        $token = $user->createToken('old')->accessToken;

        $this->actingAs($user)->postJson('/_kinetix/tokens', ['name' => 'new'])->assertForbidden();
        $this->actingAs($user)->getJson('/_kinetix/tokens')->assertOk();
        $this->actingAs($user)->deleteJson('/_kinetix/tokens/'.$token->getKey())->assertOk();
        $this->assertNull($token->fresh());

        // Teams off: a sealed token stays listable and revocable.
        $sealed = $user->createToken('sealed', ['*', 'team:9'])->accessToken;
        $this->actingAs($user)->getJson('/_kinetix/tokens')->assertJsonFragment(['id' => $sealed->getKey()]);
        $this->actingAs($user)->deleteJson('/_kinetix/tokens/'.$sealed->getKey())->assertOk();
        $this->assertNull($sealed->fresh());

        Gate::define('mint-tokens', fn (): bool => true);

        $this->actingAs($user)->postJson('/_kinetix/tokens', ['name' => 'new'])->assertCreated();

        // Teams off: no seal is minted, the stamp still is.
        $this->assertEqualsCanonicalizing(['*', 'integration'], $user->tokens()->where('name', 'new')->firstOrFail()->abilities);
    }
}
