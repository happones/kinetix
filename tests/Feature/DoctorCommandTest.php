<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Feature;

use Happones\Kinetix\Permissions\KinetixPermissions;
use Happones\Kinetix\Permissions\PermissionRegistry;
use Happones\Kinetix\Support\PublishedFiles;
use Happones\Kinetix\Tests\Concerns\CreatesPermissionTables;
use Happones\Kinetix\Tests\Fakes\BranchMemberFields;
use Happones\Kinetix\Tests\Fakes\NoLockCacheStore;
use Happones\Kinetix\Tests\TestCase;
use Illuminate\Cache\Repository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Spatie\Permission\PermissionServiceProvider;

/** An app-side role writer, for the doctor's assign_role check. */
class DoctorMemberRoles
{
    public function sync(): void {}
}

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

    public function test_the_default_member_roles_follow_the_roles_screen(): void
    {
        config()->set('kinetix.membership.enabled', true);
        config()->set('kinetix.membership.assignable_roles', null);

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('assignable roles follow the Roles screen');
    }

    public function test_a_member_list_naming_the_super_admin_is_flagged(): void
    {
        config()->set('kinetix.membership.enabled', true);
        config()->set('kinetix.membership.assignable_roles', ['editor', 'super-admin']);

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('assignable_roles names a protected role');
    }

    public function test_app_assigned_member_roles_are_not_cross_checked(): void
    {
        config()->set('kinetix.membership.enabled', true);
        config()->set('kinetix.membership.assignable_roles', ['cashier']);
        config()->set('kinetix.membership.assign_role', [DoctorMemberRoles::class, 'sync']);

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('app-assigned role(s)');
    }

    public function test_member_fields_must_name_a_member_fields_class_and_need_their_column(): void
    {
        config()->set('kinetix.membership.enabled', true);
        config()->set('kinetix.membership.fields', \stdClass::class);

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('membership.fields is not a MemberFields class')
            ->assertFailed();

        config()->set('kinetix.membership.fields', BranchMemberFields::class);
        Schema::create('kinetix_member_provisions', function (Blueprint $table): void {
            $table->id();
        });

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('provisions table has no `fields` column')
            ->assertFailed();
    }

    public function test_a_closure_in_member_fields_is_reported_by_the_config_scan(): void
    {
        config()->set('kinetix.membership.fields', fn () => null);

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('closure(s) in Kinetix config')
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

    public function test_social_login_linking_on_any_email_is_a_warning(): void
    {
        config()->set('kinetix.connected_accounts.enabled', true);
        config()->set('kinetix.connected_accounts.login_enabled', true);
        config()->set('kinetix.connected_accounts.link_by_email', 'always');

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('links to any account with the same email');
    }

    public function test_social_login_linking_on_verified_emails_is_not_flagged(): void
    {
        config()->set('kinetix.connected_accounts.enabled', true);
        config()->set('kinetix.connected_accounts.login_enabled', true);

        $this->artisan('kinetix:doctor')
            ->doesntExpectOutputToContain('links to any account with the same email');
    }

    public function test_a_closure_as_a_social_login_callback_is_an_error(): void
    {
        config()->set('kinetix.connected_accounts.email_verified', fn (): bool => true);

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('kinetix.connected_accounts.email_verified')
            ->assertFailed();
    }

    public function test_a_closure_as_the_owner_bypass_rule_is_an_error(): void
    {
        config()->set('kinetix.permissions.owner_bypass', ['using' => fn (): bool => true, 'except' => []]);

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('closure(s) in Kinetix config')
            ->expectsOutputToContain('kinetix.permissions.owner_bypass.using')
            ->assertFailed();
    }

    public function test_the_owner_bypass_summary_states_what_it_grants(): void
    {
        $this->enableOwnerBypass(['using' => true, 'except' => ['settings']]);

        $total = count(app(PermissionRegistry::class)->allPermissions());

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain(sprintf(
                'owner bypass on — %d of %d registered abilities (platform: users.impersonate; except: settings)',
                $total - 2,
                $total,
            ))
            ->doesntExpectOutputToContain('match no registered feature or ability')
            ->assertSuccessful();
    }

    public function test_the_legacy_owner_bypass_is_summarized_without_a_scope(): void
    {
        $this->enableOwnerBypass(true);

        $total = count(app(PermissionRegistry::class)->allPermissions());

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain(sprintf(
                'owner bypass on — %d of %d registered abilities (platform: users.impersonate)',
                $total - 1,
                $total,
            ))
            ->assertSuccessful();
    }

    public function test_owner_bypass_patterns_that_match_nothing_are_a_warning(): void
    {
        $this->enableOwnerBypass(['using' => true, 'only' => ['posts', 'reprots'], 'except' => ['setings', 'posts.*', '*']]);

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('3 owner_bypass pattern(s) match no registered feature or ability')
            ->expectsOutputToContain('reprots')
            ->expectsOutputToContain('setings')
            ->assertSuccessful();
    }

    public function test_a_published_legacy_owner_bypass_is_not_a_missing_key(): void
    {
        $package                                = require __DIR__.'/../../config/kinetix.php';
        $package['permissions']['owner_bypass'] = [CallbackTarget::class, 'instanceMethod'];
        File::put(config_path('kinetix.php'), '<?php return '.var_export($package, true).';');

        try {
            $this->artisan('kinetix:doctor')
                ->expectsOutputToContain('config/kinetix.php has every key the package knows')
                ->doesntExpectOutputToContain('permissions.owner_bypass.using')
                ->assertSuccessful();
        } finally {
            File::delete(config_path('kinetix.php'));
        }
    }

    public function test_a_published_minimal_owner_bypass_is_not_a_missing_key(): void
    {
        $package                                = require __DIR__.'/../../config/kinetix.php';
        $package['permissions']['owner_bypass'] = ['using' => true];
        File::put(config_path('kinetix.php'), '<?php return '.var_export($package, true).';');

        try {
            $this->artisan('kinetix:doctor')
                ->expectsOutputToContain('config/kinetix.php has every key the package knows')
                ->doesntExpectOutputToContain('permissions.owner_bypass.only')
                ->doesntExpectOutputToContain('permissions.owner_bypass.except')
                ->assertSuccessful();
        } finally {
            File::delete(config_path('kinetix.php'));
        }
    }

    public function test_an_assigned_role_carrying_a_platform_permission_is_a_warning(): void
    {
        $this->seedPlatformRole('admin', assigned: true);

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('1 assigned role(s) carry a platform-level permission (users.impersonate)')
            ->expectsOutputToContain("Role::findByName('admin')->revokePermissionTo('users.impersonate')")
            ->expectsOutputToContain('KinetixRolesSeeder')
            ->assertSuccessful();
    }

    public function test_a_protected_role_carrying_a_platform_permission_is_not_flagged(): void
    {
        config()->set('kinetix.permissions.protected_roles', ['super-admin', 'admin']);
        $this->seedPlatformRole('admin', assigned: true);

        $this->artisan('kinetix:doctor')
            ->doesntExpectOutputToContain('carry a platform-level permission')
            ->assertSuccessful();
    }

    public function test_an_unassigned_platform_role_is_not_flagged(): void
    {
        $this->seedPlatformRole('support', assigned: false);

        $this->artisan('kinetix:doctor')
            ->doesntExpectOutputToContain('carry a platform-level permission')
            ->assertSuccessful();
    }

    /**
     * A role holding `users.impersonate`, as the pre-v0.273 seeder made the
     * `admin` preset, optionally held by a user.
     */
    private function seedPlatformRole(string $name, bool $assigned): void
    {
        config()->set('kinetix.permissions.enabled', true);
        config()->set('kinetix.permissions.teams', false);

        $this->createPermissionTables();
        KinetixPermissions::feature('users')->ability('impersonate')->platform('impersonate');

        Permission::findOrCreate('users.impersonate', 'web');
        $role = Role::findOrCreate($name, 'web');
        $role->givePermissionTo('users.impersonate');

        if ($assigned) {
            DB::table('model_has_roles')->insert(['role_id' => $role->id, 'model_type' => 'App\\Models\\User', 'model_id' => 1]);
        }
    }

    /**
     * Permissions on at runtime, with the features the boot pass would have
     * registered and a platform ability to report.
     */
    private function enableOwnerBypass(mixed $ownerBypass): void
    {
        config()->set('kinetix.permissions.enabled', true);
        config()->set('kinetix.permissions.teams', false);
        config()->set('kinetix.permissions.owner_bypass', $ownerBypass);

        KinetixPermissions::feature('posts')->crud();
        KinetixPermissions::feature('settings')->ability('manage');
        KinetixPermissions::feature('users')->ability('impersonate')->platform('impersonate');
    }

    public function test_team_billing_no_role_can_manage_is_a_warning(): void
    {
        config()->set('kinetix.billing.enabled', true);
        config()->set('kinetix.permissions.enabled', true);
        config()->set('kinetix.permissions.teams', false);
        $this->createPermissionTables();

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('team billing is on but no role grants billing.manage')
            ->assertSuccessful();

        Permission::findOrCreate('billing.manage', 'web');
        Role::findOrCreate('admin', 'web')->givePermissionTo('billing.manage');

        $this->artisan('kinetix:doctor')
            ->doesntExpectOutputToContain('no role grants billing.manage')
            ->assertSuccessful();
    }

    public function test_team_billing_without_permissions_or_ownership_is_a_warning(): void
    {
        config()->set('kinetix.billing.enabled', true);

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('team billing is on but nobody can manage it')
            ->assertSuccessful();

        config()->set('kinetix.billing.authorize', [DoctorMemberRoles::class, 'sync']);

        $this->artisan('kinetix:doctor')
            ->doesntExpectOutputToContain('nobody can manage it')
            ->assertSuccessful();
    }

    public function test_a_closure_billable_resolver_is_a_config_cache_error(): void
    {
        config()->set('kinetix.billing.resolve_billable', fn ($user) => $user);

        $this->artisan('kinetix:doctor')
            ->expectsOutputToContain('closure(s) in Kinetix config')
            ->expectsOutputToContain('kinetix.billing.resolve_billable')
            ->assertFailed();
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

    public function test_keys_missing_from_the_published_config_are_named(): void
    {
        $package = require __DIR__.'/../../config/kinetix.php';
        unset($package['inbox']['mirror_sent'], $package['billing']['lock_plans_during_trial']);
        // A list of choices the app narrowed is not a missing key.
        $package['locale']['locales'] = array_slice((array) $package['locale']['locales'], 0, 1, true);
        File::put(config_path('kinetix.php'), '<?php return '.var_export($package, true).';');

        try {
            $this->artisan('kinetix:doctor')
                ->expectsOutputToContain('2 key(s) of the package are missing from config/kinetix.php')
                ->expectsOutputToContain('inbox.mirror_sent')
                ->expectsOutputToContain('billing.lock_plans_during_trial')
                ->doesntExpectOutputToContain('locale.locales')
                ->assertSuccessful();
        } finally {
            File::delete(config_path('kinetix.php'));
        }
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
        // Read state is tenant-aware too, and is flagged on its own.
        (require __DIR__.'/../../database/migrations/2026_01_01_000028_add_team_id_to_kinetix_announcement_views_table.php')->up();

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

    public function test_a_table_query_timeout_on_sqlite_is_flagged(): void
    {
        config()->set('kinetix.tables.query_timeout', 2000);

        $findings = $this->sectionFindings('Table limits');

        $this->assertCount(1, $findings);
        $this->assertSame('warning', $findings[0]['level']);
        $this->assertStringContainsString('kinetix.tables.query_timeout', $findings[0]['message']);
    }

    public function test_the_table_limits_stay_quiet_when_nothing_is_set(): void
    {
        $this->assertSame([], $this->sectionFindings('Table limits'));
    }

    public function test_an_array_store_for_cached_totals_is_flagged_outside_testing(): void
    {
        config()->set('kinetix.tables.cached_total.store', 'array');
        $this->app['env'] = 'production';

        $findings = $this->sectionFindings('Table limits');

        $this->assertCount(1, $findings);
        $this->assertStringContainsString('cached_total.store', $findings[0]['message']);
    }

    /**
     * @return array<int, array{level: string, message: string}>
     */
    private function sectionFindings(string $section): array
    {
        $this->withoutMockingConsoleOutput();
        Artisan::call('kinetix:doctor', ['--json' => true]);

        /** @var array{findings: array<int, array{section: string, level: string, message: string}>} $payload */
        $payload = json_decode(Artisan::output(), true);

        return array_values(array_filter($payload['findings'], static fn (array $finding): bool => $finding['section'] === $section));
    }

    /**
     * @return array<int, array{level: string, message: string}>
     */
    private function searchLimitFindings(): array
    {
        $this->withoutMockingConsoleOutput();
        Artisan::call('kinetix:doctor', ['--json' => true]);

        /** @var array{findings: array<int, array{section: string, level: string, message: string}>} $payload */
        $payload = json_decode(Artisan::output(), true);

        return array_values(array_filter($payload['findings'], static fn (array $finding): bool => $finding['section'] === 'Search limits'));
    }

    public function test_the_search_limits_are_silent_while_off(): void
    {
        $this->assertSame([], $this->searchLimitFindings());
    }

    public function test_a_concurrency_cap_on_the_array_store_is_a_warning(): void
    {
        config()->set('kinetix.search.concurrency', 3);

        $findings = $this->searchLimitFindings();

        $this->assertSame('warning', $findings[0]['level']);
        $this->assertStringContainsString('array cache store', $findings[0]['message']);
    }

    public function test_a_concurrency_cap_on_a_shared_lock_store_is_fine(): void
    {
        config(['cache.default' => 'file', 'kinetix.search.concurrency' => 3]);
        Cache::purge('file');

        $findings = $this->searchLimitFindings();

        $this->assertSame('ok', $findings[0]['level']);
    }

    public function test_a_timeout_on_a_read_write_split_is_a_warning(): void
    {
        config([
            'kinetix.search.timeout'     => 500,
            'database.default'           => 'split',
            'database.connections.split' => ['driver' => 'pgsql', 'read' => ['host' => 'replica'], 'write' => ['host' => 'primary']],
        ]);

        $findings = $this->searchLimitFindings();

        $this->assertSame('warning', $findings[0]['level']);
        $this->assertStringContainsString('read/write split', $findings[0]['message']);
    }

    public function test_a_concurrency_cap_without_locks_is_a_warning(): void
    {
        Cache::extend('nolock', fn (): Repository => new Repository(new NoLockCacheStore));
        config(['cache.stores.nolock' => ['driver' => 'nolock'], 'cache.default' => 'nolock', 'kinetix.search.concurrency' => 3]);
        Cache::purge('nolock');

        $findings = $this->searchLimitFindings();

        $this->assertSame('warning', $findings[0]['level']);
        $this->assertStringContainsString('cannot lock', $findings[0]['message']);
    }

    public function test_a_concurrency_cap_on_the_database_store_is_a_warning(): void
    {
        config(['cache.default' => 'database', 'kinetix.search.concurrency' => 3]);
        Cache::purge('database');

        $findings = $this->searchLimitFindings();

        $this->assertSame('warning', $findings[0]['level']);
        $this->assertStringContainsString('database cache store', $findings[0]['message']);
    }

    public function test_a_timeout_on_a_driver_without_one_is_a_warning(): void
    {
        config()->set('kinetix.search.timeout', 500);

        $findings = $this->searchLimitFindings();

        $this->assertSame('warning', $findings[0]['level']);
        $this->assertStringContainsString('has no statement timeout', $findings[0]['message']);
    }

    public function test_drifted_icon_map_is_pointed_at_register_icons_instead_of_generic_advice(): void
    {
        $target = resource_path('js/composables/useKinetixIcons.ts');
        File::ensureDirectoryExists(dirname($target));
        File::put($target, "// edited by the host\n");

        // A baseline recording a DIFFERENT hash is what marks the file as
        // locally edited.
        $manifest = PublishedFiles::manifestPath();
        File::ensureDirectoryExists(dirname($manifest));
        File::put($manifest, (string) json_encode([
            'resources/js/composables/useKinetixIcons.ts' => md5('the published contents'),
        ]));

        try {
            Artisan::call('kinetix:doctor');
            $output = Artisan::output();
        } finally {
            File::delete($target);
            File::delete($manifest);
        }

        $this->assertStringContainsString('registerIcons', $output);
        $this->assertStringContainsString('app.ts', $output);
    }

    public function test_a_stylesheet_without_the_status_tokens_is_a_warning(): void
    {
        File::ensureDirectoryExists(resource_path('css'));
        File::put(resource_path('css/app.css'), "@import 'tailwindcss';\n");

        try {
            $this->artisan('kinetix:doctor')
                ->expectsOutputToContain('maps no status tokens')
                ->assertSuccessful();

            File::put(resource_path('css/app.css'), "@import 'tailwindcss';\n@theme inline { --color-success: hsl(var(--success)); }\n");

            $this->artisan('kinetix:doctor')
                ->expectsOutputToContain('status tokens (success · warning · info) mapped')
                ->assertSuccessful();
        } finally {
            File::delete(resource_path('css/app.css'));
        }
    }
}
