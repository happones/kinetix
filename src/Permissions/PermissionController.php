<?php

declare(strict_types=1);

namespace Happones\Kinetix\Permissions;

use Happones\Kinetix\Data\PermissionFeatureData;
use Happones\Kinetix\Data\RoleData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Role & permission management endpoints, gated by the `roles.manage` ability
 * (or the super-admin bypass). All role ids are resolved by route-parameter name
 * so the optional `{current_team}` team prefix can't shift positional arguments.
 *
 * Three guardrails protect against a role-manager escalating past their own
 * level (all bypassed for a super-admin, whose Gate::before wins everything):
 *   1. Submitted permissions are allowlisted against the registry.
 *   2. A manager can only grant permissions they themselves hold.
 *   3. Protected roles (default: the super-admin role) can't be modified, and a
 *      change that would revoke the actor's own `roles.manage` is rolled back.
 */
class PermissionController
{
    public function __construct(protected PermissionRegistry $registry) {}

    /**
     * @return class-string
     */
    protected function roleModel(): string
    {
        return config('permission.models.role', Role::class);
    }

    protected function guard(): string
    {
        return (string) config('kinetix.permissions.guard', 'web');
    }

    /**
     * The permission catalog (features + abilities) for the management UI.
     */
    public function features(): JsonResponse
    {
        Gate::authorize('roles.manage');

        $features = array_map(
            static fn (Feature $feature): PermissionFeatureData => PermissionFeatureData::fromFeature($feature),
            array_values($this->registry->features()),
        );

        return response()->json($features);
    }

    public function roles(): JsonResponse
    {
        Gate::authorize('roles.manage');

        $roles = $this->roleModel()::query()
            ->with('permissions')
            ->withCount('users')
            ->get()
            ->map(static fn ($role): RoleData => RoleData::fromModel($role))
            ->values();

        return response()->json($roles);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('roles.manage');

        $request->validate($this->rules());

        $name = (string) $request->input('name');
        $this->assertNotProtected($name);

        $permissions = $request->input('permissions', []);
        $this->assertCanGrant($request->user(), $permissions);

        $role = $this->roleModel()::findOrCreate($name, $this->guard());
        $role->syncPermissions($permissions);

        return response()->json(RoleData::fromModel($role->load('permissions')), 201);
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('roles.manage');

        $request->validate($this->rules(partial: true));

        $role = $this->roleModel()::findOrFail($request->route('role'));
        $this->assertNotProtected($role->name);

        if ($request->has('permissions')) {
            $this->assertCanGrant($request->user(), $request->input('permissions', []));
        }

        // The mutation runs inside a transaction that is rolled back if it would
        // strip the actor's own `roles.manage` (self-lockout guard).
        DB::transaction(function () use ($request, $role): void {
            if ($request->filled('name') && $request->input('name') !== $role->name) {
                $this->assertNotProtected((string) $request->input('name'));
                $role->name = (string) $request->input('name');
                $role->save();
            }

            if ($request->has('permissions')) {
                $role->syncPermissions($request->input('permissions', []));
            }

            $this->assertActorRetainsManage($request->user());
        });

        return response()->json(RoleData::fromModel($role->load('permissions')));
    }

    public function destroy(Request $request): JsonResponse
    {
        Gate::authorize('roles.manage');

        $role = $this->roleModel()::findOrFail($request->route('role'));
        $this->assertNotProtected($role->name);

        DB::transaction(function () use ($role, $request): void {
            $role->delete();
            $this->assertActorRetainsManage($request->user());
        });

        return response()->json(['status' => 'success']);
    }

    /**
     * Validation rules; `partial` makes name/permissions optional for updates.
     * Permissions are allowlisted against the registry so an arbitrary key can
     * never reach `syncPermissions()`.
     *
     * @return array<string, array<int, mixed>>
     */
    protected function rules(bool $partial = false): array
    {
        $name = $partial ? ['sometimes', 'required', 'string', 'max:255'] : ['required', 'string', 'max:255'];

        return [
            'name'          => $name,
            'permissions'   => $partial ? ['sometimes', 'array'] : ['array'],
            'permissions.*' => ['string', Rule::in($this->registry->allPermissions())],
        ];
    }

    /**
     * Role names that cannot be created, renamed to, edited or deleted through
     * this UI. Defaults to the super-admin role.
     *
     * @return array<int, string>
     */
    protected function protectedRoles(): array
    {
        $configured = config('kinetix.permissions.protected_roles');

        return $configured !== null ? (array) $configured : [SuperAdmin::role()];
    }

    protected function assertNotProtected(string $roleName): void
    {
        if (in_array($roleName, $this->protectedRoles(), true)) {
            abort(403, "The '{$roleName}' role is protected and cannot be modified from here.");
        }
    }

    /**
     * A manager may only grant permissions they themselves hold; a super-admin
     * (Gate::before bypass) may grant anything.
     *
     * "Hold" is evaluated through the **Gate**, not by reading Spatie's stored
     * rows — because all enforcement flows through the Gate. A permission counts
     * as held when the Gate grants it, whether that comes from a stored
     * role/permission OR from a `Gate::before` bypass (e.g. a team **owner** whose
     * rights are granted dynamically by the host app via `$user->ownsTeam(...)`).
     * Reading `getAllPermissions()` alone would 403 such an owner, since their
     * permissions never exist as `model_has_permissions`/`role_has_permissions`
     * rows.
     *
     * @param array<int, string> $permissions
     */
    protected function assertCanGrant(mixed $user, array $permissions): void
    {
        if ($permissions === [] || $user === null || SuperAdmin::check($user)) {
            return;
        }

        $gate = Gate::forUser($user);

        $disallowed = array_values(array_filter(
            $permissions,
            static fn (string $permission): bool => $gate->denies($permission),
        ));

        if ($disallowed !== []) {
            abort(403, 'You cannot grant permissions you do not hold: '.implode(', ', $disallowed));
        }
    }

    /**
     * Abort (rolling back the surrounding transaction) if the just-applied change
     * would leave the actor without `roles.manage`. A super-admin keeps access
     * via the Gate::before bypass, so they can never lock themselves out.
     */
    protected function assertActorRetainsManage(mixed $user): void
    {
        if ($user === null) {
            return;
        }

        if (class_exists(PermissionRegistrar::class)) {
            app(PermissionRegistrar::class)->forgetCachedPermissions();
        }

        if (method_exists($user, 'unsetRelation')) {
            $user->unsetRelation('roles');
            $user->unsetRelation('permissions');
        }

        if (Gate::forUser($user)->denies('roles.manage')) {
            abort(403, 'This change would revoke your own role-management access.');
        }
    }
}
