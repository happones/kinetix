<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Support\KinetixThrottle;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Route;

class LookupThrottleTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('kinetix.tags.enabled', true);
        $app['config']->set('kinetix.help.enabled', true);
    }

    public function test_the_lookup_limiters_have_documented_defaults(): void
    {
        $this->assertSame(['search.throttle', '120,1', null], KinetixThrottle::LIMITERS['kinetix-search']);
        $this->assertSame(['search.relation_throttle', '60,1', null], KinetixThrottle::LIMITERS['kinetix-relation-picker']);
        $this->assertSame([120, 1], KinetixThrottle::parse(config('kinetix.search.throttle'), '120,1'));
        $this->assertSame([60, 1], KinetixThrottle::parse(config('kinetix.search.relation_throttle'), '60,1'));
    }

    public function test_every_lookup_route_names_its_limiter(): void
    {
        foreach ([
            'kinetix.forms.search'         => 'kinetix-search',
            'kinetix.tags.suggest'         => 'kinetix-search',
            'kinetix.help.search'          => 'kinetix-search',
            'kinetix.relations.attachable' => 'kinetix-relation-picker',
            'kinetix.relations.associable' => 'kinetix-relation-picker',
        ] as $route => $limiter) {
            $this->assertContains(
                KinetixThrottle::middleware($limiter),
                Route::getRoutes()->getByName($route)?->gatherMiddleware() ?? [],
                "{$route} is not throttled by {$limiter}.",
            );
        }
    }

    public function test_a_throttled_search_answers_the_shared_429_shape(): void
    {
        config()->set('kinetix.search.throttle', '2,1');
        $user = new GenericUser(['id' => 7]);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $this->actingAs($user)->postJson('/_kinetix/forms/search', ['token' => 'x', 'q' => 'a'])->assertStatus(400);
        }

        $response = $this->actingAs($user)->postJson('/_kinetix/forms/search', ['token' => 'x', 'q' => 'a'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'throttled')
            ->assertHeader('Retry-After');

        $this->assertStringStartsWith('Too many attempts.', (string) $response->json('message'));
        $this->assertGreaterThan(0, $response->json('retry_after'));

        // The budget is per user: someone else still searches.
        $this->actingAs(new GenericUser(['id' => 8]))->postJson('/_kinetix/forms/search', ['token' => 'x', 'q' => 'a'])->assertStatus(400);
    }

    public function test_tag_suggestions_share_the_search_budget_and_pickers_have_their_own(): void
    {
        config()->set('kinetix.search.throttle', '1,1');
        config()->set('kinetix.search.relation_throttle', '1,1');
        $user = new GenericUser(['id' => 7]);

        $this->actingAs($user)->postJson('/_kinetix/forms/search', ['token' => 'x'])->assertStatus(400);
        $this->actingAs($user)->getJson('/_kinetix/tags/suggest?q=a')->assertStatus(429);

        // The picker budget is separate: its first call is not throttled.
        $this->assertNotSame(429, $this->actingAs($user)->postJson(route('kinetix.relations.attachable'), ['descriptor' => 'x'])->status());
        $this->assertSame(429, $this->actingAs($user)->postJson(route('kinetix.relations.attachable'), ['descriptor' => 'x'])->status());
    }

    public function test_a_null_throttle_never_answers_429(): void
    {
        config()->set('kinetix.search.throttle', null);
        $user = new GenericUser(['id' => 7]);

        for ($attempt = 1; $attempt <= 130; $attempt++) {
            $this->assertNotSame(429, $this->actingAs($user)->postJson('/_kinetix/forms/search', ['token' => 'x'])->status());
        }
    }
}
