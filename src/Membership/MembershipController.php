<?php

declare(strict_types=1);

namespace Happones\Kinetix\Membership;

use Happones\Kinetix\Data\MemberProvisionData;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Spatie\Permission\PermissionRegistrar;

/**
 * Admin-provisioned membership: an admin adds an email + role, the person
 * activates by setting a password. Unlike the starter-kit invitation flow, no
 * personal team is created and the role is a dynamic Kinetix role drawn from a
 * curated allow-list — so a provisioner can never escalate someone to `admin`.
 *
 * Management endpoints are gated by `members.*` abilities; activation is public
 * but protected by a temporary signed URL. All ids are resolved by route-parameter
 * name so the optional `{current_team}` prefix can't shift positional arguments.
 */
class MembershipController
{
    public function index(Request $request): JsonResponse
    {
        Gate::authorize('members.viewAny');

        $teamId = $this->teamId($request);

        $provisions = MemberProvision::query()
            ->when($teamId !== null, fn ($query) => $query->where('team_id', $teamId))
            ->when($teamId === null, fn ($query) => $query->whereNull('team_id'))
            ->latest()
            ->get()
            ->map(static fn (MemberProvision $provision): MemberProvisionData => MemberProvisionData::fromModel($provision))
            ->values();

        return response()->json([
            'provisions'       => $provisions,
            'assignable_roles' => $this->assignableRoles(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('members.provision');

        $validated = $request->validate([
            'email' => ['required', 'email'],
            'role'  => ['required', 'string'],
        ]);

        $this->assertAssignable($validated['role']);

        $provision = MemberProvision::updateOrCreate(
            ['team_id' => $this->teamId($request), 'email' => $validated['email']],
            [
                'role'         => $validated['role'],
                'invited_by'   => $request->user()?->getKey(),
                'user_id'      => null,
                'status'       => MemberProvisionStatus::Pending,
                'activated_at' => null,
                'expires_at'   => now()->addHours($this->expiryHours()),
            ],
        );

        $this->sendActivationLink($provision);

        return response()->json(MemberProvisionData::fromModel($provision), 201);
    }

    public function resend(Request $request): JsonResponse
    {
        Gate::authorize('members.provision');

        $provision = $this->findProvision($request);

        $provision->update([
            'status'     => MemberProvisionStatus::Pending,
            'expires_at' => now()->addHours($this->expiryHours()),
        ]);

        $this->sendActivationLink($provision);

        return response()->json(MemberProvisionData::fromModel($provision));
    }

    public function update(Request $request): JsonResponse
    {
        Gate::authorize('members.update');

        $provision = $this->findProvision($request);

        $validated = $request->validate(['role' => ['required', 'string']]);
        $this->assertAssignable($validated['role']);

        $previousRole = $provision->role;
        $provision->update(['role' => $validated['role']]);

        if ($provision->user_id !== null) {
            $this->withTeam($provision->team_id, function () use ($provision, $previousRole, $validated): void {
                $user = $this->resolveUser($provision->user_id);

                if ($user !== null && method_exists($user, 'assignRole') && method_exists($user, 'removeRole')) {
                    $user->removeRole($previousRole);
                    $user->assignRole($validated['role']);
                }
            });
        }

        return response()->json(MemberProvisionData::fromModel($provision));
    }

    public function destroy(Request $request): JsonResponse
    {
        Gate::authorize('members.revoke');

        $provision = $this->findProvision($request);

        if ($provision->user_id !== null) {
            $this->withTeam($provision->team_id, function () use ($provision): void {
                $user = $this->resolveUser($provision->user_id);

                if ($user !== null && method_exists($user, 'removeRole')) {
                    $user->removeRole($provision->role);
                }
            });

            $detach = config('kinetix.membership.detach_member');

            if (is_callable($detach)) {
                $detach($this->resolveUser($provision->user_id), $provision);
            }
        }

        $provision->update(['status' => MemberProvisionStatus::Revoked]);

        return response()->json(['status' => 'success']);
    }

    /**
     * Public set-password page. Reachable only through a valid signed URL; the
     * provision must still be pending and unexpired.
     */
    public function showActivation(Request $request): InertiaResponse
    {
        $provision = $this->pendingProvisionOrFail($request);

        return Inertia::render(
            (string) config('kinetix.membership.activation_view', 'Kinetix/MemberActivation'),
            [
                'email'  => $provision->email,
                'action' => $request->fullUrl(),
            ],
        );
    }

    /**
     * Complete activation: create the host's User, attach to the team (via the
     * optional host callback), assign the Kinetix role and sign the person in.
     */
    public function activate(Request $request): RedirectResponse
    {
        $provision = $this->pendingProvisionOrFail($request);

        $validated = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        // Re-check the allow-list at activation time, in case config changed
        // between provisioning and the person clicking the link.
        $this->assertAssignable($provision->role);

        $userModel = $this->userModel();

        $user = $userModel::create([
            'name'     => $validated['name'],
            'email'    => $provision->email,
            'password' => Hash::make($validated['password']),
        ]);

        // Kinetix never touches the host's team pivot; the host attaches the
        // user to its team here if it tracks team membership itself.
        $attach = config('kinetix.membership.attach_member');

        if (is_callable($attach)) {
            $attach($user, $provision);
        }

        $this->withTeam($provision->team_id, static function () use ($user, $provision): void {
            if (method_exists($user, 'assignRole')) {
                $user->assignRole($provision->role);
            }
        });

        $provision->update([
            'status'       => MemberProvisionStatus::Active,
            'activated_at' => now(),
            'user_id'      => $user->getKey(),
            'name'         => $validated['name'],
        ]);

        if ($user instanceof Authenticatable) {
            Auth::login($user);
        }

        return redirect()->intended((string) config('kinetix.membership.redirect_after', '/'));
    }

    /**
     * Roles a provisioner is allowed to assign. The security boundary for the
     * "members never become admin" guarantee.
     *
     * @return array<int, string>
     */
    protected function assignableRoles(): array
    {
        return array_values((array) config('kinetix.membership.assignable_roles', []));
    }

    protected function assertAssignable(string $role): void
    {
        abort_unless(in_array($role, $this->assignableRoles(), true), 422, 'Role is not assignable.');
    }

    protected function teamId(Request $request): int|string|null
    {
        if (! config('kinetix.membership.teams', false)) {
            return null;
        }

        return $request->route('current_team') ?? $request->user()?->currentTeam?->getKey();
    }

    protected function expiryHours(): int
    {
        return (int) config('kinetix.membership.activation_expiry', 72);
    }

    protected function findProvision(Request $request): MemberProvision
    {
        return MemberProvision::findOrFail($request->route('provision'));
    }

    protected function pendingProvisionOrFail(Request $request): MemberProvision
    {
        $provision = MemberProvision::findOrFail($request->route('provision'));

        abort_unless($provision->isPending(), 410, 'This activation link is no longer valid.');
        abort_if($provision->isExpired(), 410, 'This activation link has expired.');

        return $provision;
    }

    /**
     * @return class-string<Model>
     */
    protected function userModel(): string
    {
        return (string) config('kinetix.membership.user_model', 'App\\Models\\User');
    }

    protected function resolveUser(int|string|null $id): ?Model
    {
        if ($id === null) {
            return null;
        }

        return $this->userModel()::find($id);
    }

    /**
     * Run a callback with spatie's team id pinned to the provision's team, so
     * role (un)assignment lands in the right tenant scope, then restore it.
     */
    protected function withTeam(int|string|null $teamId, callable $callback): void
    {
        if (! config('kinetix.membership.teams', false) || ! class_exists(PermissionRegistrar::class)) {
            $callback();

            return;
        }

        $registrar = app(PermissionRegistrar::class);
        $previous  = $registrar->getPermissionsTeamId();

        $registrar->setPermissionsTeamId($teamId);

        try {
            $callback();
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }

    protected function sendActivationLink(MemberProvision $provision): void
    {
        $url = URL::temporarySignedRoute(
            'kinetix.membership.activate.show',
            $provision->expires_at ?? now()->addHours($this->expiryHours()),
            ['provision' => $provision->getKey()],
        );

        Notification::route('mail', $provision->email)
            ->notify(new MemberActivationNotification($url, $provision));
    }
}
