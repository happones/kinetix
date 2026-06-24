<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Permissions\PermissionRegistry;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Permission;
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

        $created = 0;

        foreach ($declared as $name) {
            $existed = $permissionClass::where('name', $name)->where('guard_name', $guard)->exists();
            $permissionClass::findOrCreate($name, $guard);

            if (! $existed) {
                $created++;
                $this->line("  <fg=green>+</> {$name}");
            }
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

        return self::SUCCESS;
    }
}
