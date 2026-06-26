<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Forms\Components\TextInput;
use Happones\Kinetix\Forms\Components\Toggle;
use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Settings\KinetixSettings;
use Happones\Kinetix\Settings\SettingsPage;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionServiceProvider;
use Spatie\Permission\Traits\HasRoles;

class GeneralSettingsPage extends SettingsPage
{
    public function schema(): array
    {
        return [
            TextInput::make('site_name')->required(),
            Toggle::make('maintenance_mode'),
        ];
    }
}

class SettingsUser extends Authenticatable
{
    use HasRoles;

    protected $table = 'users';

    public $timestamps = false;

    protected $guarded = [];

    protected $guard_name = 'web';
}

class SettingsTest extends TestCase
{
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
        $app['config']->set('kinetix.settings.enabled', true);
        $app['config']->set('kinetix.settings.pages', [GeneralSettingsPage::class]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->createTables();

        foreach (KinetixPermissions::all() as $name) {
            Permission::findOrCreate($name, 'web');
        }
    }

    private function createTables(): void
    {
        Schema::create('users', function ($table) {
            $table->increments('id');
            $table->string('name')->nullable();
        });

        foreach (['permissions', 'roles'] as $name) {
            Schema::create($name, function ($table) {
                $table->bigIncrements('id');
                $table->string('name');
                $table->string('guard_name');
                $table->timestamps();
                $table->unique(['name', 'guard_name']);
            });
        }

        Schema::create('role_has_permissions', function ($table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('model_has_permissions', function ($table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });

        Schema::create('model_has_roles', function ($table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->primary(['role_id', 'model_id', 'model_type']);
        });

        Schema::create('kinetix_settings', function ($table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('key');
            $table->text('value')->nullable();
            $table->boolean('encrypted')->default(false);
            $table->timestamps();
            $table->unique(['team_id', 'key']);
        });
    }

    private function manager(): SettingsUser
    {
        $user = SettingsUser::create(['name' => 'Manager']);
        $user->givePermissionTo('settings.manage');

        return $user;
    }

    public function test_manager_get_set_forget_and_default(): void
    {
        $this->assertSame('fallback', KinetixSettings::get('general.site_name', 'fallback'));

        KinetixSettings::set('general.site_name', 'Acme');
        $this->assertSame('Acme', KinetixSettings::get('general.site_name'));

        // Re-set reflects immediately (cache invalidated on write).
        KinetixSettings::set('general.site_name', 'Acme 2');
        $this->assertSame('Acme 2', KinetixSettings::get('general.site_name'));

        KinetixSettings::forget('general.site_name');
        $this->assertSame('gone', KinetixSettings::get('general.site_name', 'gone'));
    }

    public function test_values_keep_their_type(): void
    {
        KinetixSettings::set('general.maintenance_mode', true);
        KinetixSettings::set('general.limits', ['max' => 5]);

        $this->assertTrue(KinetixSettings::get('general.maintenance_mode'));
        $this->assertSame(['max' => 5], KinetixSettings::get('general.limits'));
    }

    public function test_encrypted_value_round_trips_and_is_not_stored_in_clear(): void
    {
        KinetixSettings::set('general.api_key', 'super-secret', encrypted: true);

        $this->assertSame('super-secret', KinetixSettings::get('general.api_key'));
        $this->assertDatabaseMissing('kinetix_settings', ['value' => '"super-secret"']);
    }

    public function test_settings_page_persists_and_validates(): void
    {
        $manager = $this->manager();

        // Missing required site_name → validation error.
        $this->actingAs($manager)
            ->putJson('/_kinetix/settings/general', ['maintenance_mode' => true])
            ->assertStatus(422);

        // Valid payload persists under the prefixed keys.
        $this->actingAs($manager)
            ->putJson('/_kinetix/settings/general', ['site_name' => 'Acme', 'maintenance_mode' => true])
            ->assertOk()
            ->assertJsonFragment(['status' => 'success']);

        $this->assertSame('Acme', KinetixSettings::get('general.site_name'));
        $this->assertTrue(KinetixSettings::get('general.maintenance_mode'));
    }

    public function test_unknown_page_returns_404(): void
    {
        $this->actingAs($this->manager())
            ->putJson('/_kinetix/settings/nope', ['x' => 1])
            ->assertNotFound();
    }

    public function test_users_without_permission_are_denied(): void
    {
        $nobody = SettingsUser::create(['name' => 'Nobody']);

        $this->actingAs($nobody)
            ->putJson('/_kinetix/settings/general', ['site_name' => 'Acme'])
            ->assertForbidden();
    }
}
