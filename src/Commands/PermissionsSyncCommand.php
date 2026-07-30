<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Permissions\PermissionRegistry;
use Happones\Kinetix\Permissions\SuperAdmin;
use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Console\Command;
use Illuminate\Database\QueryException;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

class PermissionsSyncCommand extends Command
{
    protected $signature = 'kinetix:permissions:sync {--prune : Delete permissions that are no longer in the registry}';

    protected $description = 'Sync the Kinetix permission registry into spatie/laravel-permission';

    public function handle(PermissionRegistry $registry): int
    {
        $permissionClass = config('permission.models.permission', Permission::class);

        if (! class_exists($permissionClass)) {
            $this->error('spatie/laravel-permission is not installed. Run: composer require spatie/laravel-permission');

            return self::FAILURE;
        }

        $guard    = (string) config('kinetix.permissions.guard', 'web');
        $declared = $registry->allPermissions();

        if ($declared === []) {
            $this->warn('No permissions are declared. Register features via KinetixPermissions::feature()/resource().');

            return self::SUCCESS;
        }

        // Fetch existing names in one query instead of an exists() per permission.
        $existing = array_flip(
            $permissionClass::where('guard_name', $guard)
                ->whereIn('name', $declared)
                ->pluck('name')
                ->all()
        );

        $created = 0;

        foreach ($declared as $name) {
            if (isset($existing[$name])) {
                continue;
            }

            $permissionClass::findOrCreate($name, $guard);
            $created++;
            $this->line("  <fg=green>+</> {$name}");
        }

        $this->info("Synced {$created} new permission(s) of ".count($declared).' declared.');

        if ($this->option('prune')) {
            $pruned = $permissionClass::where('guard_name', $guard)
                ->whereNotIn('name', $declared)
                ->get();

            foreach ($pruned as $permission) {
                $this->line("  <fg=red>-</> {$permission->name}");
                $permission->delete();
            }

            $this->info("Pruned {$pruned->count()} permission(s) not in the registry.");
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->warnAboutTeamlessRoles();

        return self::SUCCESS;
    }

    /**
     * With team scoping on, a role whose team key is NULL is **global**: visible
     * in every team and editable by super-admins only. That is intentional for
     * the platform super-admin, but a seeder that ran without team context
     * silently produces global `admin`/`editor`/… roles — which then can't be
     * managed from the team UI. Surface them instead of letting them puzzle
     * someone later.
     */
    protected function warnAboutTeamlessRoles(): void
    {
        $roleClass = config('permission.models.role', Role::class);

        if (! KinetixTeams::enabledFor('permissions')
            || ! config('permission.teams', false)
            || ! class_exists($roleClass)) {
            return;
        }

        $teamColumn = (string) config('permission.column_names.team_foreign_key', 'team_id');
        $exempt     = SuperAdmin::protectedRoles();

        try {
            $global = $roleClass::query()
                ->whereNull($teamColumn)
                ->whereNotIn('name', $exempt)
                ->pluck('name')
                ->all();
        } catch (QueryException) {
            // The teams migration hasn't run yet — the mismatch warning the
            // service provider logs at boot already covers that case.
            return;
        }

        if ($global === []) {
            return;
        }

        $this->newLine();
        $this->warn('Global (teamless) roles found: '.implode(', ', $global));
        $this->line('  <fg=gray>Team scoping is on, so these are visible in EVERY team and editable by a</>');
        $this->line('  <fg=gray>super-admin only. Intentional for platform-wide roles; if they came from a</>');
        $this->line('  <fg=gray>seeder that ran without team context, re-seed them with the team id set:</>');
        $this->line('  <fg=gray>app(PermissionRegistrar::class)->setPermissionsTeamId($team->id);</>');
    }
}
