<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Accessibility\AccessibilityManager;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;

class A11yUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class AccessibilityTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.accessibility.enabled', true);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        Schema::create('kinetix_accessibility', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->unique();
            $table->json('preferences')->nullable();
            $table->timestamps();
        });
    }

    private function user(): A11yUser
    {
        return A11yUser::create(['name' => 'Ada']);
    }

    public function test_index_returns_defaults_for_a_new_user(): void
    {
        $this->actingAs($this->user())
            ->getJson('/_kinetix/accessibility')
            ->assertOk()
            ->assertJsonPath('reducedMotion', false)
            ->assertJsonPath('textSize', 'normal');
    }

    public function test_update_persists_and_normalizes_preferences(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->postJson('/_kinetix/accessibility', [
                'reducedMotion' => true,
                'textSize'      => 'large',
            ])
            ->assertOk()
            ->assertJsonPath('reducedMotion', true)
            ->assertJsonPath('textSize', 'large');

        $fresh = app(AccessibilityManager::class)->for($user->fresh());
        $this->assertTrue($fresh->reducedMotion);
        $this->assertSame('large', $fresh->textSize);
    }

    public function test_invalid_text_size_is_rejected(): void
    {
        $this->actingAs($this->user())
            ->postJson('/_kinetix/accessibility', ['textSize' => 'huge'])
            ->assertStatus(422);
    }

    public function test_preferences_are_isolated_per_user(): void
    {
        $a = $this->user();
        $b = $this->user();

        app(AccessibilityManager::class)->update($a, ['highContrast' => true]);

        $this->assertFalse(app(AccessibilityManager::class)->for($b)->highContrast);
    }

    public function test_unauthenticated_requests_are_rejected(): void
    {
        $this->getJson('/_kinetix/accessibility')->assertStatus(401);
    }
}
