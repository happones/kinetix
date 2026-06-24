<?php

declare(strict_types=1);

namespace Happones\Kinetix\Permissions;

use Happones\Kinetix\Data\PermissionFeatureData;
use Happones\Kinetix\Data\RoleData;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Spatie\Permission\Models\Role;

/**
 * Role & permission management endpoints, gated by the `roles.manage` ability
 * (or the super-admin bypass). All role ids are resolved by route-parameter name
 * so the optional `{current_team}` team prefix can't shift positional arguments.
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
            ->get()
            ->map(static fn ($role): RoleData => RoleData::fromModel($role))
            ->values();

        return response()->json($roles);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('roles.manage');

        $request->validate([
            'name'          => ['required', 'string', 'max:255'],
            'permissions'   => ['array'],
            'permissions.*' => ['string'],
        ]);

        $role = $this->roleModel()::findOrCreate((string) $request->input('name'), $this->guard());
        $role->syncPermissions($request->input('permissions', []));

        return response()->json(RoleData::fromModel($role->load('permissions')), 201);
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('roles.manage');

        $request->validate([
            'name'          => ['sometimes', 'required', 'string', 'max:255'],
            'permissions'   => ['sometimes', 'array'],
            'permissions.*' => ['string'],
        ]);

        $role = $this->roleModel()::findOrFail($request->route('role'));

        if ($request->filled('name') && $request->input('name') !== $role->name) {
            $role->name = (string) $request->input('name');
            $role->save();
        }

        if ($request->has('permissions')) {
            $role->syncPermissions($request->input('permissions', []));
        }

        return response()->json(RoleData::fromModel($role->load('permissions')));
    }

    public function destroy(Request $request): JsonResponse
    {
        Gate::authorize('roles.manage');

        $this->roleModel()::findOrFail($request->route('role'))->delete();

        return response()->json(['status' => 'success']);
    }
}
