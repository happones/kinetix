<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Makes spatie/laravel-permission's tables teams-ready the *hybrid* way: the
 * team foreign key is nullable and lives OUTSIDE the primary key, so a role can
 * be assigned either to a specific team or globally (team NULL — e.g. a
 * platform super-admin that keeps access in every team).
 *
 * Differences from spatie's own `add_teams_fields` stub, which makes the pivot
 * team key part of the primary key with a non-null default — under that schema
 * a teamless assignment is impossible:
 *   - pivot `team_id` is nullable (no default) and indexed, not in the PK;
 *   - uniqueness moves to a unique index that includes the team key.
 *
 * Requires `'teams' => true` in config/permission.php. Idempotent: each table
 * is skipped if the team column already exists (e.g. spatie's stock teams
 * migration already ran — see the Kinetix permissions docs for converting).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! config('permission.teams')) {
            return;
        }

        $tableNames  = config('permission.table_names');
        $columnNames = config('permission.column_names');

        if (empty($tableNames)) {
            throw new Exception('Error: config/permission.php not loaded. Run [php artisan config:clear] and try again.');
        }

        $teamKey         = $columnNames['team_foreign_key']     ?? 'team_id';
        $pivotRole       = $columnNames['role_pivot_key']       ?? 'role_id';
        $pivotPermission = $columnNames['permission_pivot_key'] ?? 'permission_id';
        $morphKey        = $columnNames['model_morph_key']      ?? 'model_id';

        if (! Schema::hasColumn($tableNames['roles'], $teamKey)) {
            Schema::table($tableNames['roles'], function (Blueprint $table) use ($teamKey): void {
                $table->unsignedBigInteger($teamKey)->nullable()->after('id');
                $table->index($teamKey, 'roles_team_foreign_key_index');

                $table->dropUnique('roles_name_guard_name_unique');
                $table->unique([$teamKey, 'name', 'guard_name']);
            });
        }

        if (! Schema::hasColumn($tableNames['model_has_permissions'], $teamKey)) {
            Schema::table($tableNames['model_has_permissions'], function (Blueprint $table) use ($tableNames, $teamKey, $pivotPermission, $morphKey): void {
                $table->unsignedBigInteger($teamKey)->nullable();
                $table->index($teamKey, 'model_has_permissions_team_foreign_key_index');

                if (DB::getDriverName() !== 'sqlite') {
                    $table->dropForeign([$pivotPermission]);
                }
                $table->dropPrimary();

                // Unique instead of primary so the team key can stay nullable.
                $table->unique([$teamKey, $pivotPermission, $morphKey, 'model_type'],
                    'model_has_permissions_team_permission_model_unique');
                if (DB::getDriverName() !== 'sqlite') {
                    $table->foreign($pivotPermission)
                        ->references('id')->on($tableNames['permissions'])->onDelete('cascade');
                }
            });
        }

        if (! Schema::hasColumn($tableNames['model_has_roles'], $teamKey)) {
            Schema::table($tableNames['model_has_roles'], function (Blueprint $table) use ($tableNames, $teamKey, $pivotRole, $morphKey): void {
                $table->unsignedBigInteger($teamKey)->nullable();
                $table->index($teamKey, 'model_has_roles_team_foreign_key_index');

                if (DB::getDriverName() !== 'sqlite') {
                    $table->dropForeign([$pivotRole]);
                }
                $table->dropPrimary();

                $table->unique([$teamKey, $pivotRole, $morphKey, 'model_type'],
                    'model_has_roles_team_role_model_unique');
                if (DB::getDriverName() !== 'sqlite') {
                    $table->foreign($pivotRole)
                        ->references('id')->on($tableNames['roles'])->onDelete('cascade');
                }
            });
        }

        app('cache')
            ->store(config('permission.cache.store') != 'default' ? config('permission.cache.store') : null)
            ->forget(config('permission.cache.key'));
    }

    /**
     * Deliberately not reversible.
     *
     * Rolling this back would mean dropping the team key from spatie's pivots and
     * restoring their original primary keys — which silently discards every
     * team-scoped role assignment (rows that only differ by team would collide on
     * the restored PK) and destroys any global assignment. There is no safe
     * automatic inverse, so this fails loudly instead of pretending to undo.
     */
    public function down(): void
    {
        throw new RuntimeException(
            'This migration is not reversible: reverting it would drop the team key from '
            .'spatie\'s permission pivots and discard every team-scoped role assignment. '
            .'Restore from a backup instead.'
        );
    }
};
