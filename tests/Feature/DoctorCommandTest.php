<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Tests\Concerns\CreatesPermissionTables;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;

class DoctorCommandTest extends TestCase
{
    use CreatesPermissionTables;

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
    }

    protected function tearDown(): void
    {
        File::deleteDirectory(resource_path('js/types'));
        File::delete(resource_path('js/vue-i18n-locales.generated.js'));
        File::delete(resource_path('js/vue-i18n-locales.generated.ts'));

        parent::tearDown();
    }

    public function test_a_clean_install_reports_no_errors(): void
    {
        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('Everything checks out')
            ->assertSuccessful();
    }

    public function test_it_reports_the_resolved_endpoint_prefix(): void
    {
        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('{current_team}/_kinetix')
            ->assertSuccessful();
    }

    public function test_half_enabled_team_scoping_is_an_error(): void
    {
        config()->set('kinetix.permissions.enabled', true);
        config()->set('kinetix.permissions.teams', true);
        config()->set('permission.teams', false);

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('permission.teams is false')
            ->assertFailed();
    }

    public function test_membership_without_attach_member_is_an_error(): void
    {
        config()->set('kinetix.membership.enabled', true);
        config()->set('kinetix.membership.teams', true);
        config()->set('kinetix.membership.attach_member', null);

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('attach_member is null')
            ->assertFailed();
    }

    public function test_a_closure_in_config_is_an_error(): void
    {
        config()->set('kinetix.membership.attach_member', fn () => null);

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('closure(s) in Kinetix config')
            ->assertFailed();
    }

    public function test_the_serializable_callback_forms_are_not_flagged(): void
    {
        config()->set('kinetix.membership.attach_member', [CallbackTarget::class, 'instanceMethod']);
        config()->set('kinetix.permissions.owner_bypass', CallbackTarget::class);

        $this->artisan('kinetix:doctor')
            ->doesntExpectOutputToContain('closure(s) in Kinetix config')
            ->assertSuccessful();
    }

    public function test_duplicate_route_names_are_an_error(): void
    {
        // Exactly the accident that makes a real endpoint look broken: the host
        // registers its own controller under a Kinetix route name, `route()`
        // resolves to theirs, and the component still calls the real URL.
        Route::post('my-uploads', fn () => 'host')->name('kinetix.uploads.store');

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('duplicated kinetix.* route name')
            ->assertFailed();
    }

    public function test_two_i18n_bundles_are_a_warning(): void
    {
        File::ensureDirectoryExists(resource_path('js'));
        File::put(resource_path('js/vue-i18n-locales.generated.js'), 'export default {}');
        File::put(resource_path('js/vue-i18n-locales.generated.ts'), 'export default {}');

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('two bundles present')
            ->assertSuccessful();   // a warning must not fail the command
    }

    public function test_a_legacy_types_barrel_is_a_warning(): void
    {
        File::ensureDirectoryExists(resource_path('js/types'));
        File::put(resource_path('js/types/index.ts'), 'export interface KinetixAction { name: string }');

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain("Kinetix's old published file")
            ->assertSuccessful();
    }

    public function test_a_host_owned_types_barrel_is_left_alone(): void
    {
        File::ensureDirectoryExists(resource_path('js/types'));
        File::put(resource_path('js/types/index.ts'), "export * from './auth';\nexport * from './teams';\n");

        $this->artisan('kinetix:doctor')
            ->doesntExpectOutputToContain("Kinetix's old published file")
            ->assertSuccessful();
    }

    public function test_teamless_roles_are_a_warning(): void
    {
        config()->set('kinetix.permissions.enabled', true);
        config()->set('kinetix.permissions.teams', true);
        config()->set('permission.teams', true);

        $this->createPermissionTables();
        $migration = require __DIR__.'/../../database/migrations/2026_01_01_000017_add_kinetix_team_fields_to_permission_tables.php';
        $migration->up();

        app(PermissionRegistrar::class)->setPermissionsTeamId(null);
        Role::findOrCreate('editor', 'web');

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('teamless (global) role')
            ->assertSuccessful();
    }

    public function test_platform_wide_modules_are_stated(): void
    {
        config()->set('kinetix.billing.enabled', true);

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('platform-wide by design: billing plans')
            ->assertSuccessful();
    }

    public function test_tenant_aware_modules_are_not_listed_as_platform_wide(): void
    {
        // These became tenant-aware in v0.121.0 / v0.122.0.
        config()->set('kinetix.mail_templates.enabled', true);
        config()->set('kinetix.announcements.enabled', true);
        config()->set('kinetix.api_logs.enabled', true);

        $this->artisan('kinetix:doctor')
            ->doesntExpectOutputToContain('platform-wide by design')
            ->assertSuccessful();
    }

    public function test_nothing_is_stated_without_teams(): void
    {
        config()->set('kinetix.teams', false);
        config()->set('kinetix.billing.enabled', true);

        $this->artisan('kinetix:doctor')
            ->doesntExpectOutputToContain('platform-wide by design')
            ->assertSuccessful();
    }

    public function test_a_missing_tenant_column_is_an_error(): void
    {
        config()->set('kinetix.announcements.enabled', true);

        // The pre-tenant schema: the app published the package but never ran the
        // migration that adds team_id.
        (require __DIR__.'/../../database/migrations/2026_01_01_000014_create_kinetix_announcements_table.php')->up();

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('missing their team_id column')
            ->expectsOutputToContain('kinetix_announcements')
            ->assertFailed();
    }

    public function test_a_migrated_table_is_not_flagged(): void
    {
        config()->set('kinetix.announcements.enabled', true);

        (require __DIR__.'/../../database/migrations/2026_01_01_000014_create_kinetix_announcements_table.php')->up();
        (require __DIR__.'/../../database/migrations/2026_01_01_000025_add_team_id_to_kinetix_announcements_table.php')->up();

        $this->artisan('kinetix:doctor')
            ->doesntExpectOutputToContain('missing their team_id column')
            ->assertSuccessful();
    }

    public function test_json_output_carries_the_findings_and_counts(): void
    {
        config()->set('kinetix.membership.enabled', true);
        config()->set('kinetix.membership.teams', true);

        $this->withoutMockingConsoleOutput();
        $exit = Artisan::call('kinetix:doctor', ['--json' => true]);

        /** @var array{errors: int, warnings: int, findings: array<int, array{section: string, level: string}>} $payload */
        $payload = json_decode(Artisan::output(), true);

        $this->assertSame(1, $exit);
        $this->assertGreaterThan(0, $payload['errors']);
        $this->assertContains('Membership', array_column($payload['findings'], 'section'));
    }
}
