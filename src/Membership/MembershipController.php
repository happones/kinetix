<?php

declare(strict_types=1);

namespace Happones\Kinetix\Membership;

use Happones\Kinetix\Activity\KinetixActivity;
use Happones\Kinetix\Credentials\KinetixIdentity;
use Happones\Kinetix\Credentials\KinetixPasswords;
use Happones\Kinetix\Data\MemberProvisionData;
use Happones\Kinetix\Permissions\AssignableRoles;
use Happones\Kinetix\Permissions\SuperAdmin;
use Happones\Kinetix\Support\ConfigCallback;
use Happones\Kinetix\Support\HostKeys;
use Happones\Kinetix\Support\KinetixTeams;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Support\Arrayable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Enumerable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Spatie\Permission\Models\Role;
use Throwable;

/**
 * Admin-provisioned membership: an admin adds an email + role, the person
 * activates by setting a password. Unlike the starter-kit invitation flow, no
 * personal team is created and the role is a dynamic Kinetix role drawn from a
 * curated allow-list — so a provisioner can never escalate someone to `admin`.
 *
 * Management endpoints are gated by `members.*` abilities; activation is public
 * but protected by a temporary signed URL. All ids are resolved by route-parameter
 * name so the optional `{current_team}` prefix can't shift positional arguments.
 *
 * ## Two axes, both defaulting to today's behavior
 *
 * `membership.provisioning` — **`activation`** creates no User until the person
 * sets their own password; **`direct`** creates it immediately with a temporary
 * credential. Direct trades the "no password-less accounts pile up" invariant
 * for working with no delivery channel at all, which is the whole point when
 * your staff have no email address.
 *
 * `membership.delivery` — **`mail`** sends the activation link; **`manual`**
 * sends nothing and hands the credential back to the admin ONCE, to pass on in
 * person.
 *
 * `membership.identifier` — which field identifies the member (`email`,
 * `username` or `phone`); see the Credentials module.
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

        $assignable = $this->assignableRoles($teamId);

        return response()->json([
            'provisions'       => $provisions,
            'assignable_roles' => $assignable,
            // What the UI needs to explain itself: how big each role is, which
            // field identifies a member, which roles are never handed out here
            // (super-admin is the team owner's), and which actions this viewer
            // may take — so it hides a button instead of answering it with 403.
            'roles'            => $this->roleDetails($assignable, $teamId),
            'identifier_field' => $this->identifierField(),
            'reserved_roles'   => SuperAdmin::protectedRoles(),
            'can'              => [
                'provision'   => Gate::allows('members.provision'),
                'update'      => Gate::allows('members.update'),
                'revoke'      => Gate::allows('members.revoke'),
                'credentials' => Gate::allows('members.credentials'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        Gate::authorize('members.provision');

        $field = $this->identifierField();

        $validated = $request->validate([
            $field => $this->identifierRules($field),
            'name' => ['nullable', 'string', 'max:255'],
            'role' => ['required', 'string'],
        ]);

        $teamId     = $this->teamId($request);
        $identifier = KinetixIdentity::normalize($field, (string) $validated[$field]);

        $this->assertAssignable($validated['role'], $teamId);

        // `updateOrCreate` on purpose: a repeat on a known identifier has to
        // look exactly like a fresh one, or provisioning becomes a way to test
        // whether somebody is already a member.
        $provision = MemberProvision::updateOrCreate(
            ['team_id' => $teamId, $field => $identifier],
            [
                'role'         => $validated['role'],
                'name'         => $validated['name'] ?? null,
                'invited_by'   => $request->user()?->getKey(),
                'user_id'      => null,
                'status'       => MemberProvisionStatus::Pending,
                'activated_at' => null,
                'expires_at'   => now()->addHours($this->expiryHours()),
            ],
        );

        $credential = $this->isDirect()
            ? $this->provisionDirectly($provision, $request)
            : $this->issueActivation($provision);

        return response()->json(
            $this->payload($provision, $credential),
            201,
        );
    }

    /**
     * Re-issue the credential for a member — a new temporary password, or a new
     * signed activation link. It is always REGENERATED, never retrieved: the
     * old one is unreadable by design, which is what "shown once" means.
     *
     * Behind its own ability: seeing a credential that lets you become someone
     * else is a bigger privilege than adding them to the directory.
     */
    public function credential(Request $request): JsonResponse
    {
        Gate::authorize('members.credentials');

        $provision = $this->findProvision($request);

        abort_if(
            $provision->status === MemberProvisionStatus::Revoked,
            422,
            'This member was revoked; provision them again instead.',
        );

        $credential = $provision->user_id !== null
            ? $this->issueTemporaryPassword($provision, $request)
            : $this->issueActivation($provision, force: true);

        return response()->json($this->payload($provision, $credential));
    }

    public function resend(Request $request): JsonResponse
    {
        Gate::authorize('members.provision');

        $provision = $this->findProvision($request);

        // Resend only makes sense for a pending (possibly expired) provision.
        // Resurrecting an ACTIVE one would mint an activation link whose
        // submit creates a duplicate user; a REVOKED member must be
        // re-provisioned deliberately, not revived by a resend.
        //
        // A member provisioned DIRECTLY is active from the start, so there is
        // no link to resend — what they need is a new temporary password, which
        // is a bigger privilege and lives on its own endpoint.
        abort_if(
            $provision->status === MemberProvisionStatus::Active,
            422,
            $provision->user_id !== null
                ? 'This member already has an account; issue a new credential instead.'
                : 'This member is already active.',
        );
        abort_if($provision->status === MemberProvisionStatus::Revoked, 422, 'This member was revoked; provision them again instead.');

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

        // A revoked member keeps their user_id for the audit trail, so a role
        // change here would silently RE-GRANT them a role. Re-provision instead.
        abort_if($provision->status === MemberProvisionStatus::Revoked, 422, 'This member was revoked; provision them again instead.');

        $validated = $request->validate(['role' => ['required', 'string']]);
        $this->assertAssignable($validated['role'], $provision->team_id);

        $previousRole = $provision->role;
        $provision->update(['role' => $validated['role']]);

        $user = $provision->user_id !== null ? $this->resolveUser($provision->user_id) : null;

        if ($user !== null) {
            KinetixMembership::syncRole($user, $provision, from: $previousRole, to: $validated['role']);
        }

        return response()->json(MemberProvisionData::fromModel($provision));
    }

    public function destroy(Request $request): JsonResponse
    {
        Gate::authorize('members.revoke');

        KinetixMembership::revoke($this->findProvision($request));

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
                // `identifier` is whichever field carries it; `email` stays for
                // apps whose published page still reads that prop.
                'identifier' => $provision->identifier(),
                'email'      => $provision->email,
                'action'     => $request->fullUrl(),
                // Who is inviting them: a set-password page that names no
                // business reads like any phishing form.
                'team' => $this->teamName($provision),
            ],
        );
    }

    /**
     * The name of the team a provision joins, when the app's Team model has
     * one. Null without teams, or when it can't be read.
     */
    protected function teamName(MemberProvision $provision): ?string
    {
        $model = $provision->team_id !== null ? HostKeys::teamModel() : null;

        if ($model === null) {
            return null;
        }

        $name = $model::query()->find($provision->team_id)?->getAttribute('name');

        return is_string($name) && $name !== '' ? $name : null;
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
        // between provisioning and the person clicking the link. The activation
        // route carries no `{current_team}` segment, so the allow-list is
        // resolved against the team the provision was created for.
        $this->assertAssignable($provision->role, $provision->team_id);

        $userModel = $this->userModel();

        $user = $userModel::create([
            ...$this->identifierAttributes($provision),
            'name'     => $validated['name'],
            'password' => Hash::make($validated['password']),
        ]);

        // Kinetix never touches the host's team pivot; the host attaches the
        // user to its team here if it tracks team membership itself.
        $attach = ConfigCallback::resolve(config('kinetix.membership.attach_member'));

        if ($attach !== null) {
            $attach($user, $provision);
        }

        KinetixMembership::syncRole($user, $provision, from: null, to: $provision->role);

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
     * `null` (the default) follows the Roles screen: the team's roles plus
     * global ones ({@see AssignableRoles}) when the permissions module is on,
     * else the legacy `editor`/`viewer` pair. A static array covers a fixed
     * catalog; a closure or the class-string of an invokable class
     * (config:cache-safe) receives the team key the provision belongs to.
     *
     * Whatever the source, the protected roles (super-admin by default) are
     * removed: that role belongs to the team owner and is never handed out
     * through membership, even by a host list that names it.
     *
     * @return array<int, string>
     */
    protected function assignableRoles(int|string|null $teamId = null): array
    {
        $reserved = SuperAdmin::protectedRoles();

        return array_values(array_filter(
            array_keys($this->roleCatalog($teamId)),
            static fn (string $role): bool => $role !== '' && ! in_array($role, $reserved, true),
        ));
    }

    /**
     * The allow-list as configured, before the protected roles are removed:
     * role name → the label and description the app gave it, if any.
     *
     * Entries may be names, role models, or — for roles that come from the
     * app's own logic rather than the Roles screen —
     * `['name' => 'cashier', 'label' => 'Cashier', 'description' => '…']`.
     *
     * @return array<string, array{label: string|null, description: string|null}>
     */
    protected function roleCatalog(int|string|null $teamId): array
    {
        $catalog = [];

        foreach ($this->configuredRoles($teamId) as $entry) {
            $name = match (true) {
                $entry instanceof Model => (string) $entry->getAttribute('name'),
                is_array($entry)        => (string) ($entry['name'] ?? ''),
                default                 => (string) $entry,
            };

            if ($name === '' || isset($catalog[$name])) {
                continue;
            }

            $catalog[$name] = [
                'label'       => is_array($entry) && filled($entry['label'] ?? null) ? (string) $entry['label'] : null,
                'description' => is_array($entry) && filled($entry['description'] ?? null) ? (string) $entry['description'] : null,
            ];
        }

        return $catalog;
    }

    /**
     * The raw allow-list entries.
     *
     * @return array<int, mixed>
     */
    protected function configuredRoles(int|string|null $teamId): array
    {
        $configured = config('kinetix.membership.assignable_roles');

        if ($configured === null) {
            return $this->followsRolesScreen()
                ? AssignableRoles::names($teamId)
                : ['editor', 'viewer'];
        }

        // A `[Class::class, 'method']` pair resolves to a callback; any other
        // array is the static allow-list itself.
        $callback = ConfigCallback::resolve($configured);

        if ($callback !== null) {
            $configured = $callback($teamId);
        }

        // A Collection keeps its models (`toArray()` would flatten them to
        // attribute arrays), so both a `pluck('name')` and a `Role` collection
        // are accepted.
        if ($configured instanceof Enumerable) {
            $configured = $configured->all();
        } elseif ($configured instanceof Arrayable) {
            $configured = $configured->toArray();
        }

        return array_values((array) $configured);
    }

    /**
     * Whether the default allow-list can read the Roles screen's catalog.
     */
    protected function followsRolesScreen(): bool
    {
        return (bool) config('kinetix.permissions.enabled', false)
            && class_exists((string) config('permission.models.role', Role::class));
    }

    /**
     * Each assignable role with what the picker can say about it: the app's
     * own label/description when it gave one, and — for Roles-screen roles —
     * how many permissions it grants and whether it is global (shared by
     * every team). Counts are null for a role that isn't a Roles-screen row:
     * an app-defined role, or any role once `membership.assign_role` hands
     * assignment to the app.
     *
     * @param  array<int, string>                                                                                                 $names
     * @return array<int, array{name: string, label: string|null, description: string|null, permissions: int|null, global: bool}>
     */
    protected function roleDetails(array $names, int|string|null $teamId): array
    {
        $rows    = [];
        $catalog = $this->roleCatalog($teamId);

        if ($names !== []
            && $this->followsRolesScreen()
            && ConfigCallback::resolve(config('kinetix.membership.assign_role')) === null) {
            try {
                $teamsKey = (string) (config('permission.column_names.team_foreign_key') ?? 'team_id');
                $teams    = (bool) config('permission.teams', false);

                foreach (AssignableRoles::query($teamId)->whereIn('name', $names)->withCount('permissions')->get() as $role) {
                    $name = (string) $role->getAttribute('name');

                    // A team's own role shadows a global one of the same name.
                    if (isset($rows[$name]) && $rows[$name]['global'] === false) {
                        continue;
                    }

                    $rows[$name] = [
                        'name'        => $name,
                        'permissions' => (int) $role->getAttribute('permissions_count'),
                        'global'      => $teams && $role->getAttribute($teamsKey) === null,
                    ];
                }
            } catch (Throwable) {
                // Role tables not migrated yet: fall through to bare names.
            }
        }

        return array_map(
            static fn (string $name): array => [
                'name'        => $name,
                'label'       => $catalog[$name]['label']       ?? null,
                'description' => $catalog[$name]['description'] ?? null,
                'permissions' => $rows[$name]['permissions']    ?? null,
                'global'      => $rows[$name]['global']         ?? false,
            ],
            $names,
        );
    }

    protected function assertAssignable(string $role, int|string|null $teamId = null): void
    {
        abort_unless(in_array($role, $this->assignableRoles($teamId), true), 422, 'Role is not assignable.');
    }

    protected function teamId(Request $request): int|string|null
    {
        if (! KinetixTeams::enabledFor('membership')) {
            return null;
        }

        // Translates a slug/uuid route segment to the team's primary key and
        // verifies the user's membership (404 otherwise).
        return KinetixTeams::currentTeamKey($request);
    }

    protected function expiryHours(): int
    {
        return (int) config('kinetix.membership.activation_expiry', 72);
    }

    /**
     * Resolve a provision for the ADMIN endpoints, constrained to the team the
     * request is scoped to — exactly like index(). Without this, an admin of one
     * team could update, resend or revoke another team's invitation by id, and
     * `resend` in particular would hand them an activation link for it.
     */
    protected function findProvision(Request $request): MemberProvision
    {
        $teamId = $this->teamId($request);

        return MemberProvision::query()
            ->when($teamId !== null, fn ($query) => $query->where('team_id', $teamId))
            ->when($teamId === null, fn ($query) => $query->whereNull('team_id'))
            ->whereKey($request->route('provision'))
            ->firstOrFail();
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
        return KinetixMembership::user($id);
    }

    /**
     * Run a callback with spatie's team id pinned to the provision's team.
     */
    protected function withTeam(int|string|null $teamId, callable $callback): void
    {
        KinetixMembership::withTeam($teamId, $callback);
    }

    // -----------------------------------------------------------------
    // Provisioning modes
    // -----------------------------------------------------------------

    protected function isDirect(): bool
    {
        return config('kinetix.membership.provisioning', 'activation') === 'direct';
    }

    protected function deliversManually(): bool
    {
        return config('kinetix.membership.delivery', 'mail') === 'manual';
    }

    /**
     * The field a member is provisioned with. Constrained to what the
     * Credentials module actually accepts, so a provision can never be created
     * under an identifier nobody could then sign in with.
     */
    protected function identifierField(): string
    {
        $field = (string) config('kinetix.membership.identifier', 'email');

        return KinetixIdentity::accepts($field) ? $field : 'email';
    }

    /**
     * @return array<int, mixed>
     */
    protected function identifierRules(string $field): array
    {
        return match ($field) {
            'email'    => ['required', 'email'],
            'username' => ['required', 'string', 'max:32', 'regex:'.KinetixIdentity::resolver()->usernamePattern()],
            'phone'    => ['required', 'string', 'max:20'],
            default    => ['required', 'string'],
        };
    }

    /**
     * `direct`: create the host User now with a temporary credential, attach it
     * to the team and assign the role — the whole of activation, done by the
     * admin, because there is nobody to send a link to.
     */
    protected function provisionDirectly(MemberProvision $provision, Request $request): MemberCredential
    {
        $userModel = $this->userModel();

        /** @var Model $user */
        $user = $userModel::create([
            ...$this->identifierAttributes($provision),
            'name' => $provision->name ?? $provision->identifier(),
            // Replaced immediately below; a User is never persisted with a
            // password anyone could guess, not even for one statement.
            'password' => Hash::make(Str::random(40)),
        ]);

        $credential = $this->issueTemporaryPassword($provision, $request, $user);

        $attach = ConfigCallback::resolve(config('kinetix.membership.attach_member'));

        if ($attach !== null) {
            $attach($user, $provision);
        }

        KinetixMembership::syncRole($user, $provision, from: null, to: $provision->role);

        $provision->update([
            'status'       => MemberProvisionStatus::Active,
            'activated_at' => now(),
            'user_id'      => $user->getKey(),
            'expires_at'   => null,
        ]);

        return $credential;
    }

    /**
     * Every identifier the provision carries, to copy onto the new user.
     *
     * Shared by both provisioning paths on purpose. When `activate()` hardcoded
     * `email`, a member provisioned by username activated into an account with
     * NO identifier at all — created, given a role, and unable to ever sign in.
     * One helper means the two paths cannot drift like that again.
     *
     * @return array<string, string>
     */
    protected function identifierAttributes(MemberProvision $provision): array
    {
        $attributes = [];

        foreach (['email', 'username', 'phone'] as $field) {
            $value = $provision->getAttribute($field);

            if (filled($value)) {
                $attributes[$field] = (string) $value;
            }
        }

        return $attributes;
    }

    /**
     * A temporary password for an already-existing user, audited.
     */
    protected function issueTemporaryPassword(
        MemberProvision $provision,
        Request $request,
        ?Model $user = null,
    ): MemberCredential {
        $user ??= $this->resolveUser($provision->user_id);

        abort_if($user === null, 422, 'This member has no account yet.');

        $plain = KinetixPasswords::issueTemporary($user);

        // Handing someone a credential that lets you become them is a
        // privileged, organization-shaping act — it belongs in the audit trail
        // with who did it. The credential itself is never recorded.
        $this->audit('member.credential.issued', $provision, $request, [
            'type' => 'password',
        ]);

        return MemberCredential::password($plain, KinetixPasswords::temporaryExpiresAt($user));
    }

    /**
     * `activation`: mint the signed link, and either send it or hand it back.
     *
     * @param bool $force re-mint and return it even when delivery would send
     */
    protected function issueActivation(MemberProvision $provision, bool $force = false): ?MemberCredential
    {
        $expiresAt = $provision->expires_at ?? now()->addHours($this->expiryHours());

        $url = URL::temporarySignedRoute(
            'kinetix.membership.activate.show',
            $expiresAt,
            ['provision' => $provision->getKey()],
        );

        $channel = $this->deliveryChannel();
        $route   = $this->notificationRoute($provision, $channel);

        // Handed over instead of sent. Four reasons, all ending the same way:
        // the admin asked for something to pass on, delivery is manual, this
        // member has no address on the delivery channel, or the notification
        // cannot speak that channel. An activation link that silently goes
        // nowhere is the one outcome worth ruling out.
        if ($force
            || $channel === 'manual'
            || $route   === null
            || ! $this->canSendOn($channel, $url, $provision)) {
            return MemberCredential::link($url, $expiresAt);
        }

        Notification::route($channel, $route)
            ->notify($this->activationNotification($url, $provision, $channel));

        return null;
    }

    /**
     * `mail` (default), `sms`, or `manual` for "send nothing".
     */
    protected function deliveryChannel(): string
    {
        $delivery = (string) config('kinetix.membership.delivery', 'mail');

        if ($delivery === 'manual') {
            return 'manual';
        }

        // SMS has no single channel name — Vonage, Twilio and the local
        // gateways each register their own, and which one is right is a
        // business decision about coverage and price. Kinetix routes over
        // whatever you name.
        return $delivery === 'sms'
            ? (string) config('kinetix.membership.sms_channel', 'vonage')
            : 'mail';
    }

    /**
     * Where an activation notification can be delivered on a channel, or null
     * when this member has no address there.
     */
    protected function notificationRoute(MemberProvision $provision, ?string $channel = null): ?string
    {
        $channel ??= $this->deliveryChannel();

        $value = $channel === 'mail' ? $provision->email : $provision->phone;

        return filled($value) ? (string) $value : null;
    }

    /**
     * Whether the notification actually implements the channel's message
     * method (`toVonage()`, `toTwilio()`, …).
     *
     * Laravel resolves that method by name at send time, so a mismatch throws
     * inside a queued job — where nobody sees it and the member never gets
     * their link. Checking first turns that into a link the admin can pass on.
     */
    protected function canSendOn(string $channel, string $url, MemberProvision $provision): bool
    {
        if ($channel === 'mail') {
            return true;
        }

        $notification = $this->activationNotification($url, $provision, $channel);

        return method_exists($notification, 'to'.Str::studly(class_basename($channel)));
    }

    /**
     * The notification to send. Replaceable, because an SMS channel needs a
     * message object only its own package defines.
     */
    protected function activationNotification(
        string $url,
        MemberProvision $provision,
        string $channel,
    ): MemberActivationNotification {
        /** @var class-string<MemberActivationNotification> $class */
        $class = config('kinetix.membership.activation_notification') ?: MemberActivationNotification::class;

        return new $class($url, $provision, $channel);
    }

    /**
     * Kept for backwards compatibility: `resend()` and any host override still
     * call this name.
     */
    protected function sendActivationLink(MemberProvision $provision): void
    {
        $this->issueActivation($provision);
    }

    /**
     * @param array<string, mixed> $properties
     */
    protected function audit(string $event, MemberProvision $provision, Request $request, array $properties = []): void
    {
        if (! config('kinetix.activity.enabled', false)) {
            return;
        }

        KinetixActivity::log($event, $provision, $properties, $request->user());
    }

    /**
     * The provision, plus the credential when there is one to show — which is
     * only ever the response that created it.
     *
     * @return array<string, mixed>
     */
    protected function payload(MemberProvision $provision, ?MemberCredential $credential): array
    {
        $payload = MemberProvisionData::fromModel($provision)->toArray();

        if ($credential !== null) {
            $payload['credential'] = $credential->toArray();
        }

        return $payload;
    }
}
