<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Support\KinetixLookupSlots;
use Happones\Kinetix\Tests\Fakes\NoLockCacheStore;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Auth\GenericUser;
use Illuminate\Cache\Repository;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Route;

class KinetixLookupSlotsTest extends TestCase
{
    /** @var array<int, bool> */
    private array $heldInside = [];

    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('cache.default', 'array');
        $app['config']->set('kinetix.tags.enabled', true);
        $app['config']->set('kinetix.help.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Route::get('/_slots-probe', function () {
            foreach ($this->canLock() ? [0, 1] : [] as $slot) {
                $this->heldInside[$slot] = ! Cache::lock($this->key($slot))->get();
            }

            return response()->json(['ok' => true]);
        })->middleware(KinetixLookupSlots::ALIAS);

        Route::get('/_slots-boom', fn () => throw new \RuntimeException('boom'))->middleware(KinetixLookupSlots::ALIAS);
    }

    private function canLock(): bool
    {
        return Cache::getStore() instanceof LockProvider;
    }

    private function key(int $slot): string
    {
        return 'kinetix:lookup:'.sha1(GenericUser::class.'#7').":{$slot}";
    }

    private function user(): GenericUser
    {
        return new GenericUser(['id' => 7]);
    }

    public function test_it_is_off_by_default(): void
    {
        $this->assertNull(KinetixLookupSlots::configured());

        $this->actingAs($this->user())->getJson('/_slots-probe')->assertOk();

        $this->assertSame([0 => false, 1 => false], $this->heldInside);
    }

    public function test_a_request_holds_one_slot_and_frees_it(): void
    {
        config()->set('kinetix.search.concurrency', 2);

        $this->actingAs($this->user())->getJson('/_slots-probe')->assertOk();

        $this->assertSame([0 => true, 1 => false], $this->heldInside);
        $this->assertTrue(Cache::lock($this->key(0))->get(), 'The slot was not released.');
    }

    public function test_with_every_slot_taken_it_answers_the_busy_429(): void
    {
        config()->set('kinetix.search.concurrency', 2);

        $first  = Cache::lock($this->key(0), 30);
        $second = Cache::lock($this->key(1), 30);
        $this->assertTrue($first->get());
        $this->assertTrue($second->get());

        $this->actingAs($this->user())->getJson('/_slots-probe')
            ->assertStatus(429)
            ->assertJsonPath('code', 'busy')
            ->assertJsonPath('retry_after', 1)
            ->assertJsonPath('message', 'The search is busy. Try again in a moment.')
            ->assertHeader('Retry-After', '1');

        // One slot back: the lookup goes through on it.
        $second->release();
        $this->actingAs($this->user())->getJson('/_slots-probe')->assertOk();

        // Someone else has their own slots.
        $this->actingAs(new GenericUser(['id' => 8]))->getJson('/_slots-probe')->assertOk();
    }

    public function test_the_slot_is_released_when_the_lookup_fails(): void
    {
        config()->set('kinetix.search.concurrency', 1);

        $this->actingAs($this->user())->getJson('/_slots-boom')->assertStatus(500);

        $this->assertTrue(Cache::lock($this->key(0))->get(), 'A failed lookup kept its slot.');
    }

    public function test_a_guest_is_counted_by_ip(): void
    {
        config()->set('kinetix.search.concurrency', 1);

        $guestKey = 'kinetix:lookup:ip:'.sha1('127.0.0.1').':0';

        Route::get('/_slots-guest', fn () => response()->json(['held' => ! Cache::lock($guestKey)->get()]))
            ->middleware(KinetixLookupSlots::ALIAS);

        $this->getJson('/_slots-guest')->assertOk()->assertJson(['held' => true]);
        $this->assertTrue(Cache::lock($guestKey)->get(), 'The guest slot was not released.');
    }

    public function test_a_store_without_locks_does_nothing(): void
    {
        Cache::extend('nolock', fn (): Repository => new Repository(new NoLockCacheStore));
        config(['cache.stores.nolock' => ['driver' => 'nolock'], 'cache.default' => 'nolock', 'kinetix.search.concurrency' => 1]);
        Cache::purge('nolock');

        $this->actingAs($this->user())->getJson('/_slots-probe')->assertOk();
    }

    public function test_the_lookup_routes_carry_the_slots_after_their_throttle(): void
    {
        foreach (['kinetix.forms.search', 'kinetix.tags.suggest', 'kinetix.help.search', 'kinetix.relations.attachable', 'kinetix.relations.associable'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)?->gatherMiddleware() ?? [];
            $position   = array_search(KinetixLookupSlots::ALIAS, $middleware, true);

            $this->assertNotFalse($position, "{$name} has no lookup slots.");
            $this->assertStringStartsWith('throttle:', (string) $middleware[$position - 1], "{$name}: the throttle must run first.");
        }
    }

    public function test_the_real_endpoint_answers_busy_with_the_cap_reached(): void
    {
        config()->set('kinetix.search.concurrency', 1);
        $held = Cache::lock($this->key(0), 30);
        $this->assertTrue($held->get());

        $this->actingAs($this->user())->postJson('/_kinetix/forms/search', ['token' => 'x', 'q' => 'a'])
            ->assertStatus(429)
            ->assertJsonPath('code', 'busy');
    }
}
