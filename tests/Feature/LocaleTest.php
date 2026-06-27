<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Locale\KinetixLocale;
use Happones\Kinetix\Locale\LocaleManager;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\App;
use Illuminate\Support\Facades\Schema;

class LocaleUser extends Authenticatable
{
    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];
}

class LocaleTest extends TestCase
{
    /**
     * @param Application $app
     */
    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);

        $app['config']->set('kinetix.locale.enabled', true);
        $app['config']->set('kinetix.locale.locales', [
            'en' => 'English',
            'es' => 'Español',
            'fr' => 'Français',
        ]);
        $app['config']->set('auth.providers.users.model', LocaleUser::class);
    }

    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('locale')->nullable();
        });
    }

    private function user(): LocaleUser
    {
        return LocaleUser::create(['name' => 'Ada']);
    }

    public function test_switching_locale_persists_on_the_session_and_applies_it(): void
    {
        $this->post('/_kinetix/locale', ['locale' => 'es'])
            ->assertOk()
            ->assertJsonPath('locale', 'es');

        $this->assertSame('es', session('kinetix.locale'));
    }

    public function test_switching_locale_persists_on_the_user_column_when_present(): void
    {
        $user = $this->user();

        $this->actingAs($user)
            ->post('/_kinetix/locale', ['locale' => 'fr'])
            ->assertOk();

        $this->assertSame('fr', $user->fresh()->locale);
    }

    public function test_unsupported_locale_is_rejected(): void
    {
        $this->post('/_kinetix/locale', ['locale' => 'de'])
            ->assertStatus(422);

        $this->assertNull(session('kinetix.locale'));
    }

    public function test_manager_resolves_user_locale_over_session(): void
    {
        $user         = $this->user();
        $user->locale = 'fr';
        $user->save();

        session(['kinetix.locale' => 'es']);

        $manager = app(LocaleManager::class);

        $this->assertSame('fr', $manager->resolve($user));
        $this->assertSame('es', $manager->resolve(null));
    }

    public function test_manager_apply_sets_the_application_locale(): void
    {
        session(['kinetix.locale' => 'es']);

        app(LocaleManager::class)->apply(null);

        $this->assertSame('es', App::getLocale());
    }

    public function test_static_helper_exposes_options(): void
    {
        $options = KinetixLocale::options();

        $this->assertSame('en', $options[0]['code']);
        $this->assertSame('English', $options[0]['label']);
    }
}
