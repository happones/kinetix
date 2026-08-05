<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Permissions\SuperAdmin;
use Happones\Kinetix\Permissions\TeamOwner;
use Happones\Kinetix\Support\ConfigCallback;
use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Support\PublishedFiles;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * One command that surfaces every Kinetix misconfiguration that otherwise fails
 * *silently* — the class of problem you only find by reading the source: a
 * controller registered under the wrong prefix, team scoping half-enabled,
 * teamless roles, `attach_member` missing, two i18n bundles, published files
 * carrying local edits that the next `composer install` will discard.
 *
 * Exit code 1 when there is at least one error, so it can gate a deploy.
 */
class DoctorCommand extends Command
{
    protected $signature = 'kinetix:doctor {--json : Output the findings as JSON}';

    protected $description = 'Diagnose Kinetix configuration problems that otherwise fail silently';

    /** How many list items are printed before the rest is summarized (--json always has them all). */
    protected const ITEM_LIMIT = 10;

    /** @var array<int, array{section: string, level: string, message: string, hint: string|null, items: array<int, string>}> */
    protected array $findings = [];

    public function handle(): int
    {
        $this->checkRouting();
        $this->checkModules();
        $this->checkPermissions();
        $this->checkRoles();
        $this->checkMembership();
        $this->checkConfigCallbacks();
        $this->checkTenantColumns();
        $this->checkGlobalData();
        $this->checkFrontend();

        $errors   = $this->countLevel('error');
        $warnings = $this->countLevel('warning');

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'errors'   => $errors,
                'warnings' => $warnings,
                'findings' => $this->findings,
            ], JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES));

            return $errors > 0 ? self::FAILURE : self::SUCCESS;
        }

        $this->render();

        return $errors > 0 ? self::FAILURE : self::SUCCESS;
    }

    // ---------------------------------------------------------------- checks

    protected function checkRouting(): void
    {
        $prefix = (string) config('kinetix.route_prefix', '_kinetix');
        $teams  = (bool) config('kinetix.teams', false);
        $mount  = ($teams ? '{current_team}/'.$prefix : $prefix).'/…';

        $this->ok('Routing', "endpoints mounted under {$mount}", 'php artisan kinetix:routes');

        // A host route under the same prefix, or worse, a duplicate of a
        // `kinetix.*` route name — which shadows it for route() and makes the
        // real endpoint look broken while the app's own controller answers 200.
        $names = [];

        foreach (Route::getRoutes()->getRoutes() as $route) {
            $name = $route->getName();

            if ($name !== null && str_starts_with($name, 'kinetix.')) {
                $names[$name] = ($names[$name] ?? 0) + 1;
            }
        }

        $duplicated = array_keys(array_filter($names, static fn (int $count): bool => $count > 1));

        if ($duplicated !== []) {
            $this->error_('Routing', count($duplicated).' duplicated kinetix.* route name(s)',
                'A route of yours reuses a Kinetix route name and shadows it for route(), so route() points at '
                .'your controller while the component still calls the real endpoint. Rename yours.',
                $duplicated);
        }
    }

    protected function checkModules(): void
    {
        $modules = [
            'permissions', 'membership', 'settings', 'activity', 'webhooks', 'onboarding',
            'wizards', 'billing', 'tours', 'help', 'spotlight', 'reports_center', 'confidential',
        ];

        $enabled = array_values(array_filter(
            $modules,
            static fn (string $module): bool => (bool) config("kinetix.{$module}.enabled", false),
        ));

        $this->ok('Modules', $enabled === [] ? 'none enabled (every module is opt-in)' : implode(', ', $enabled));
    }

    protected function checkPermissions(): void
    {
        if (! config('kinetix.permissions.enabled', false)) {
            return;
        }

        if (! class_exists(PermissionRegistrar::class)) {
            $this->error_('Permissions', 'spatie/laravel-permission is not installed',
                'composer require spatie/laravel-permission');

            return;
        }

        // The classic half-enabled state: Kinetix sets a team id that spatie
        // ignores, so every hasRole()/can() stays global.
        if (KinetixTeams::enabledFor('permissions') && ! config('permission.teams', false)) {
            $this->error_('Permissions', 'kinetix team scoping is on but permission.teams is false',
                "Set 'teams' => true in config/permission.php and run the hybrid teams migration "
                .'(vendor:publish --tag=kinetix-permission-team-migrations).');
        } elseif (KinetixTeams::enabledFor('permissions')) {
            $this->ok('Permissions', 'team scoping active on both sides');
        } else {
            $this->ok('Permissions', 'enabled (no team scoping)');
        }

        if (TeamOwner::enabled()) {
            $this->ok('Permissions', 'owner bypass on — grants registry abilities only, policies still run');
        }
    }

    protected function checkRoles(): void
    {
        $roleClass = config('permission.models.role', Role::class);

        if (! config('kinetix.permissions.enabled', false)
            || ! KinetixTeams::enabledFor('permissions')
            || ! config('permission.teams', false)
            || ! class_exists($roleClass)) {
            return;
        }

        $column = (string) config('permission.column_names.team_foreign_key', 'team_id');

        try {
            $global = $roleClass::query()
                ->whereNull($column)
                ->whereNotIn('name', SuperAdmin::protectedRoles())
                ->pluck('name')
                ->all();
        } catch (QueryException) {
            $this->warn_('Roles', 'could not read the roles table',
                'Run your migrations, including vendor:publish --tag=kinetix-permission-team-migrations.');

            return;
        }

        if ($global === []) {
            $this->ok('Roles', 'no unexpected global roles');

            return;
        }

        $this->warn_('Roles', count($global).' teamless (global) role(s)',
            'Global roles are visible in EVERY team and editable by super-admins only. If they came from a '
            .'seeder that ran without team context, re-seed with setPermissionsTeamId($team->id).',
            array_map('strval', $global));
    }

    protected function checkMembership(): void
    {
        if (! config('kinetix.membership.enabled', false)) {
            return;
        }

        $teams  = KinetixTeams::enabledFor('membership');
        $attach = ConfigCallback::resolve(config('kinetix.membership.attach_member'));

        if ($teams && $attach === null) {
            $this->error_('Membership', 'team scoping is on but attach_member is null',
                'Activated members get their role and belong to NO team, so a team-routed app locks them out. '
                .'Set attach_member to [SyncProvisionedMember::class, \'attach\'].');
        } elseif ($teams) {
            $this->ok('Membership', 'attach_member configured');
        } else {
            $this->ok('Membership', 'enabled (no team scoping)');
        }

        $roles = config('kinetix.membership.assignable_roles', []);

        if (ConfigCallback::resolve($roles) === null && (array) $roles === []) {
            $this->warn_('Membership', 'assignable_roles is empty',
                'No role can be assigned, so every provision attempt returns 422.');
        }

        // Role tables scope by the PERMISSIONS flag; membership routes scope by
        // the MEMBERSHIP flag. When they disagree, provisions and the roles they
        // assign live in different tenancy models.
        if ($teams !== KinetixTeams::enabledFor('permissions')) {
            $this->warn_('Membership', 'membership.teams and permissions.teams disagree',
                'Provisions are scoped by membership.teams while role rows are scoped by permissions.teams — '
                .'role assignments may land in the wrong tenant. Align both flags (or leave both null to inherit kinetix.teams).');
        }
    }

    /**
     * A closure in a config file makes `php artisan config:cache` abort, so the
     * app cannot deploy with a cached config. Catch it here rather than in CI.
     */
    protected function checkConfigCallbacks(): void
    {
        $options = [
            'kinetix.permissions.owner_bypass',
            'kinetix.membership.assignable_roles',
            'kinetix.membership.attach_member',
            'kinetix.membership.detach_member',
        ];

        $closures = array_values(array_filter(
            $options,
            static fn (string $key): bool => config($key) instanceof \Closure,
        ));

        if ($closures === []) {
            $this->ok('Config cache', 'no closures in Kinetix config');

            return;
        }

        $this->error_('Config cache', count($closures).' closure(s) in Kinetix config',
            'php artisan config:cache will abort ("non-serializable"), so the app cannot deploy with a cached '
            .'config. Use [Class::class, \'method\'] or an invokable class-string instead.', $closures);
    }

    /**
     * A module that became tenant-aware needs its `team_id` column. Publishing
     * the package without running the new migration leaves scoping silently
     * inert (writes omit the column, reads can't filter), so name it.
     */
    protected function checkTenantColumns(): void
    {
        $pending = [];

        foreach ([
            'mail_templates' => ['kinetix_mail_templates', 'kinetix-mail-templates-migrations'],
            'announcements'  => ['kinetix_announcements', 'kinetix-announcements-migrations'],
            'reports_center' => ['kinetix_report_schedules', 'kinetix-reports-center-migrations'],
            'api_logs'       => ['kinetix_api_logs', 'kinetix-api-logs-migrations'],
        ] as $module => [$table, $tag]) {
            if (! config("kinetix.{$module}.enabled", false) || ! KinetixTeams::enabledFor($module)) {
                continue;
            }

            try {
                if (Schema::hasTable($table) && ! Schema::hasColumn($table, 'team_id')) {
                    $pending[] = "{$table} (vendor:publish --tag={$tag})";
                }
            } catch (QueryException) {
                // No database to inspect — not this command's problem.
            }
        }

        if ($pending === []) {
            return;
        }

        $this->error_('Tenancy', count($pending).' table(s) are missing their team_id column',
            'The module is team-scoped but the table predates the tenant migration, so rows are neither '
            .'stamped nor filtered. Publish the tag and run migrate.', $pending);
    }

    /**
     * The modules that still store one shared pool of rows. Both are
     * platform-level catalogs where that is the point — but their routes are
     * team-prefixed like everything else, which reads as isolation, so the
     * distinction is stated rather than left to be discovered.
     */
    protected function checkGlobalData(): void
    {
        if (! (bool) config('kinetix.teams', false)) {
            return;
        }

        $global = array_keys(array_filter([
            'billing plans'     => (bool) config('kinetix.billing.enabled', false),
            'confidential keys' => (bool) config('kinetix.confidential.enabled', false),
        ]));

        if ($global === []) {
            return;
        }

        $this->ok('Tenancy', 'platform-wide by design: '.implode(', ', $global),
            'These have no team_id: the rows are shared across tenants. Gate their management behind a '
            .'platform-admin role rather than a per-team one.');
    }

    protected function checkFrontend(): void
    {
        $bundles = PublishedFiles::i18nBundles();

        if (count($bundles) > 1) {
            $this->warn_('i18n', 'two bundles present — one of them is never refreshed',
                'Vite resolves .js before .ts, so the compiled bundle is the one vue-i18n:generate is not '
                .'writing — new keys never reach the UI. Delete the stale file.', $bundles);
        } elseif ($bundles !== []) {
            $this->ok('i18n', 'one bundle: '.$bundles[0]);
        }

        $legacy = PublishedFiles::legacyTypesBarrel();

        if ($legacy !== null) {
            $this->warn_('Types', "{$legacy} is Kinetix's old published file",
                'Kinetix now publishes resources/js/types/kinetix.ts. index.ts is the starter kit\'s barrel — '
                .'restore your re-exports there and import Kinetix types from \'@/types/kinetix\'.');
        }

        $drifted = PublishedFiles::drifted();

        if ($drifted === []) {
            if (File::isDirectory(resource_path('js/components/kinetix'))) {
                $this->ok('Publishes', 'no local edits in published files');
            }

            return;
        }

        $this->warn_('Publishes', count($drifted).' published file(s) have local edits',
            'kinetix:upgrade re-publishes with --force on every composer install, so these edits will be lost. '
            .'Move the change into a wrapper, a slot, or config.', $drifted);
    }

    // --------------------------------------------------------------- output

    protected function render(): void
    {
        $this->newLine();
        $this->line('  <options=bold>Kinetix doctor</>');
        $this->newLine();

        $width = max(array_map(
            static fn (array $finding): int => strlen($finding['section']),
            $this->findings ?: [['section' => '']],
        ));

        foreach ($this->findings as $finding) {
            [$icon, $color] = match ($finding['level']) {
                'error'   => ['✗', 'red'],
                'warning' => ['!', 'yellow'],
                default   => ['✓', 'green'],
            };

            $this->line(sprintf(
                "  <fg=%s>%s</> %-{$width}s  %s",
                $color,
                $icon,
                $finding['section'],
                $finding['message'],
            ));

            foreach (array_slice($finding['items'], 0, static::ITEM_LIMIT) as $item) {
                $this->line(str_repeat(' ', $width + 6).'<fg=gray>'.$item.'</>');
            }

            $hidden = count($finding['items']) - static::ITEM_LIMIT;

            if ($hidden > 0) {
                $this->line(str_repeat(' ', $width + 6)."<fg=gray>… and {$hidden} more (--json for the full list)</>");
            }

            if ($finding['hint'] !== null && $finding['level'] !== 'ok') {
                foreach ($this->wrapHint($finding['hint'], $width) as $line) {
                    $this->line($line);
                }
            }
        }

        $errors   = $this->countLevel('error');
        $warnings = $this->countLevel('warning');

        $this->newLine();

        if ($errors === 0 && $warnings === 0) {
            $this->line('  <fg=green;options=bold>Everything checks out.</>');
            $this->newLine();

            return;
        }

        $this->line(sprintf(
            '  <fg=%s;options=bold>%d error(s), %d warning(s).</>',
            $errors > 0 ? 'red' : 'yellow',
            $errors,
            $warnings,
        ));
        $this->newLine();
    }

    /**
     * @return array<int, string>
     */
    protected function wrapHint(string $hint, int $width): array
    {
        $indent = str_repeat(' ', $width + 6);

        return array_map(
            static fn (string $line): string => '<fg=gray>'.$indent.$line.'</>',
            explode("\n", wordwrap($hint, 88, "\n")),
        );
    }

    /**
     * @param array<int, string> $items
     */
    protected function ok(string $section, string $message, ?string $hint = null, array $items = []): void
    {
        $this->add('ok', $section, $message, $hint, $items);
    }

    /**
     * @param array<int, string> $items
     */
    protected function warn_(string $section, string $message, ?string $hint = null, array $items = []): void
    {
        $this->add('warning', $section, $message, $hint, $items);
    }

    /**
     * @param array<int, string> $items
     */
    protected function error_(string $section, string $message, ?string $hint = null, array $items = []): void
    {
        $this->add('error', $section, $message, $hint, $items);
    }

    /**
     * @param array<int, string> $items
     */
    protected function add(string $level, string $section, string $message, ?string $hint, array $items): void
    {
        $this->findings[] = [
            'section' => $section,
            'level'   => $level,
            'message' => $message,
            'hint'    => $hint,
            'items'   => array_values($items),
        ];
    }

    protected function countLevel(string $level): int
    {
        return count(array_filter($this->findings, static fn (array $f): bool => $f['level'] === $level));
    }
}
