<?php

declare(strict_types=1);

namespace Happones\Kinetix\Tests\Concerns;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The host-owned tables the Membership module talks to (users + spatie's) plus
 * its own provisions table, which ships as a publishable migration.
 */
trait CreatesMembershipTables
{
    protected function createMembershipTables(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->increments('id');
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            // The identity + password-policy columns, as the Credentials
            // migrations leave them — provisioning can hand out a temporary
            // credential to somebody who has no email address.
            $table->string('username')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->timestamp('password_changed_at')->nullable();
            $table->boolean('must_change_password')->default(false);
        });

        Schema::create('kinetix_password_history', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->index();
            $table->string('password');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('name');
            $table->string('guard_name');
            $table->timestamps();
            $table->unique(['name', 'guard_name']);
        });

        Schema::create('role_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->unsignedBigInteger('role_id');
            $table->primary(['permission_id', 'role_id']);
        });

        Schema::create('model_has_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('permission_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type']);
            $table->primary(['permission_id', 'model_id', 'model_type']);
        });

        Schema::create('model_has_roles', function (Blueprint $table) {
            $table->unsignedBigInteger('role_id');
            $table->string('model_type');
            $table->unsignedBigInteger('model_id');
            $table->index(['model_id', 'model_type']);
            $table->primary(['role_id', 'model_id', 'model_type']);
        });

        Schema::create('kinetix_member_provisions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('team_id')->nullable()->index();
            $table->string('email')->nullable();
            $table->string('username')->nullable();
            $table->string('phone')->nullable();
            $table->string('name')->nullable();
            $table->string('role');
            $table->unsignedBigInteger('invited_by')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('status')->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('activated_at')->nullable();
            $table->timestamps();
            $table->unique(['team_id', 'email']);
            $table->unique(['team_id', 'username']);
            $table->unique(['team_id', 'phone']);
        });
    }
}
