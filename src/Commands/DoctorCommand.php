<?php

declare(strict_types=1);

namespace Happones\Kinetix\Commands;

use Happones\Kinetix\Channels\Channel;
use Happones\Kinetix\Confidential\Casts\ConfidentialCast;
use Happones\Kinetix\Confidential\Concerns\HasConfidentialAttributes;
use Happones\Kinetix\Credentials\IdentityResolver;
use Happones\Kinetix\Credentials\PasswordPolicy;
use Happones\Kinetix\Entitlements\Entitlement;
use Happones\Kinetix\Entitlements\EntitlementRegistry;
use Happones\Kinetix\Membership\MemberActivationNotification;
use Happones\Kinetix\Membership\MemberFields;
use Happones\Kinetix\Membership\MemberProvision;
use Happones\Kinetix\Membership\MemberProvisionStatus;
use Happones\Kinetix\NotificationPreferences\NotificationPreferenceManager;
use Happones\Kinetix\NotificationPreferences\NotificationTypeRegistry;
use Happones\Kinetix\Permissions\AssignableRoles;
use Happones\Kinetix\Permissions\PermissionRegistry;
use Happones\Kinetix\Permissions\SuperAdmin;
use Happones\Kinetix\Permissions\TeamOwner;
use Happones\Kinetix\Query\QueryBudget;
use Happones\Kinetix\ReportsCenter\Report;
use Happones\Kinetix\ReportsCenter\ReportRegistry as ReportsCenterRegistry;
use Happones\Kinetix\Resources\Resource;
use Happones\Kinetix\Spotlight\SpotlightRegistry;
use Happones\Kinetix\Spotlight\SpotlightSource;
use Happones\Kinetix\Support\Casts\EncryptedWithPlaintextFallback;
use Happones\Kinetix\Support\ConfigCallback;
use Happones\Kinetix\Support\DialCodes;
use Happones\Kinetix\Support\DiscoveryManifest;
use Happones\Kinetix\Support\HostKeyColumns;
use Happones\Kinetix\Support\HostKeys;
use Happones\Kinetix\Support\KinetixLookupSlots;
use Happones\Kinetix\Support\KinetixSharedProps;
use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Support\KinetixThrottle;
use Happones\Kinetix\Support\PublishedFiles;
use Happones\Kinetix\Support\TailwindSourceExclusions;
use Happones\Kinetix\Tokens\KinetixTokens;
use Happones\Kinetix\Webhooks\WebhookEndpoint;
use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\DatabaseStore;
use Illuminate\Console\Command;
use Illuminate\Contracts\Cache\LockProvider;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;
use Laravel\Socialite\Facades\Socialite;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Throwable;

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

    /**
     * Config sections whose keys are all optional: leaving one out is a
     * supported shape, not a key that never arrived (`owner_bypass` may be
     * just `['using' => true]`).
     *
     * @var list<string>
     */
    protected const OPTIONAL_KEY_SECTIONS = ['permissions.owner_bypass'];

    /** @var array<int, array{section: string, level: string, message: string, hint: string|null, items: array<int, string>}> */
    protected array $findings = [];

    public function handle(): int
    {
        // The command instance outlives a run (tests, a long-lived worker):
        // start from no findings.
        $this->findings = [];

        $this->checkRouting();
        $this->checkModules();
        $this->checkPermissions();
        $this->checkPolicyDelegation();
        $this->checkEntitlements();
        $this->checkBilling();
        $this->checkCredentials();
        $this->checkSocialLogin();
        $this->checkMembershipDelivery();
        $this->checkRoles();
        $this->checkMembership();
        $this->checkNotificationPreferences();
        $this->checkNotificationRouting();
        $this->checkConfigCallbacks();
        $this->checkConfigKeys();
        $this->checkTenantColumns();
        $this->checkIndexes();
        $this->checkWebhookSecrets();
        $this->checkChannelPhoneCountries();
        $this->checkTokenTeamColumn();
        $this->checkConfidentialEnvelopes();
        $this->checkThrottles();
        $this->checkSearchLimits();
        $this->checkTableLimits();
        $this->checkSharedProps();
        $this->checkDiscoveryManifest();
        $this->checkHostKeyColumns();
        $this->checkGlobalData();
        $this->checkFrontend();
        $this->checkTheme();
        $this->checkTailwindSources();

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
        $mount  = ($teams ? (KinetixTeams::inHost() ? '{current_team}.'.KinetixTeams::domain().'/'.$prefix : '{current_team}/'.$prefix) : $prefix).'/…';

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
            'entitlements', 'credentials', 'dismissals', 'channels', 'inbox', 'flows', 'notification_routing',
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

        $this->checkOwnerBypass();
        $this->checkAssignedPlatformRoles();
    }

    /**
     * A role (outside `protected_roles`) that carries a platform-level
     * permission and is held by someone. Only a super-admin may hand such a
     * role out; one that predates that rule — the seeded `admin` / `editor`
     * presets used to include `users.impersonate` — still reaches every tenant
     * through whoever holds it. One query, every team.
     */
    protected function checkAssignedPlatformRoles(): void
    {
        $platform = app(PermissionRegistry::class)->platformPermissions();

        if ($platform === [] || ! static::permissionTablesExist()) {
            return;
        }

        /** @var class-string<Model> $roleClass */
        $roleClass = config('permission.models.role', Role::class);
        $pivot     = (string) config('permission.table_names.model_has_roles', 'model_has_roles');
        $roleKey   = (string) (config('permission.column_names.role_pivot_key') ?? 'role_id');
        $roleId    = (new $roleClass)->getQualifiedKeyName();

        $names = $roleClass::query()
            ->whereNotIn('name', SuperAdmin::protectedRoles())
            ->whereHas('permissions', static function ($permissions) use ($platform): void {
                $permissions->whereIn($permissions->getModel()->qualifyColumn('name'), $platform);
            })
            ->whereExists(static function ($assigned) use ($pivot, $roleKey, $roleId): void {
                $assigned->selectRaw('1')->from($pivot)->whereColumn("{$pivot}.{$roleKey}", $roleId);
            })
            ->pluck('name')
            ->unique()
            ->values()
            ->all();

        if ($names === []) {
            return;
        }

        $first = (string) $names[0];

        $this->warn_('Permissions', count($names).' assigned role(s) carry a platform-level permission ('.implode(', ', $platform).')',
            'Whoever holds them reaches every tenant (e.g. impersonating users of other teams). As a super-admin, remove '
            .'the permission on the Roles screen, or run setPermissionsTeamId(null); '
            ."Role::findByName('{$first}')->revokePermissionTo('{$platform[0]}'); for each role. Re-running "
            .'KinetixRolesSeeder also strips it from admin/editor/viewer, but resets any edits you made to those presets.',
            array_map('strval', $names));
    }

    /**
     * Whether spatie's role/permission tables are migrated.
     */
    protected static function permissionTablesExist(): bool
    {
        foreach (['roles', 'permissions', 'role_has_permissions', 'model_has_roles'] as $table) {
            if (! Schema::hasTable((string) config("permission.table_names.{$table}", $table))) {
                return false;
            }
        }

        return true;
    }

    /**
     * State what the owner bypass actually grants — a scope is only as good as
     * its patterns, and a typo in `except` silently over-grants.
     */
    protected function checkOwnerBypass(): void
    {
        if (! TeamOwner::enabled()) {
            return;
        }

        $registry   = app(PermissionRegistry::class);
        $registered = $registry->allPermissions();
        $granted    = TeamOwner::filter($registered);
        $scope      = TeamOwner::scope();
        $platform   = array_values(array_filter($registered, $registry->isPlatform(...)));

        $details = [];

        if ($platform !== []) {
            $details[] = 'platform: '.implode(', ', $platform);
        }

        if ($scope['only'] !== null) {
            $details[] = 'only: '.($scope['only'] === [] ? '(none)' : implode(', ', $scope['only']));
        }

        if ($scope['except'] !== []) {
            $details[] = 'except: '.implode(', ', $scope['except']);
        }

        $this->ok('Permissions', sprintf(
            'owner bypass on — %d of %d registered abilities%s; policies still run',
            count($granted),
            count($registered),
            $details === [] ? '' : ' ('.implode('; ', $details).')',
        ));

        $unmatched = TeamOwner::unmatchedPatterns();

        if ($unmatched !== []) {
            $this->warn_('Permissions', count($unmatched).' owner_bypass pattern(s) match no registered feature or ability',
                'Patterns are a feature name (\'settings\' or \'settings.*\') or an exact key (\'users.impersonate\'); '
                .'other wildcards match nothing. A typo in `except` leaves that ability granted to every team owner.',
                $unmatched);
        }
    }

    /**
     * The role matrix only has EFFECT where a model policy delegates to it —
     * a policy method that hard-codes `return true;` silently ignores every
     * permission the admin toggles, which reads as "permissions don't work".
     * Flags each resource whose feature is synced into the permissions table
     * while its policy still returns static `true`s (and resources with no
     * policy at all, where nothing enforces the matrix).
     */
    protected function checkPolicyDelegation(): void
    {
        if (! config('kinetix.permissions.enabled', false) || ! class_exists(PermissionRegistrar::class)) {
            return;
        }

        $registry = app(PermissionRegistry::class);

        foreach ($registry->resolvedResourceClasses() as $resourceClass) {
            $feature = $resourceClass::permissionFeature();

            if ($feature === null) {
                continue;
            }

            $modelClass = $resourceClass::getModel();
            $policy     = Gate::getPolicyFor($modelClass);

            if ($policy === null) {
                $this->warn_(
                    'Permissions',
                    "'{$feature}' abilities are registered but ".class_basename($modelClass).' has NO policy — the role matrix has no effect on it',
                    'php artisan make:policy '.class_basename($modelClass).'Policy --model='.class_basename($modelClass)
                    ." — then delegate each ability to \$user->can('{$feature}.{ability}')",
                );

                continue;
            }

            if (! $this->featureSynced($feature)) {
                continue;
            }

            $static = $this->staticTruePolicyMethods($policy);

            if ($static !== []) {
                $this->warn_(
                    'Permissions',
                    "'{$feature}' permissions are synced, but ".class_basename($policy::class).' returns a static true for: '.implode(', ', $static),
                    "Delegate to the matrix instead — e.g. return \$user->belongsToTeam(\$record->team) && (\$user->ownsTeam(\$record->team) || \$user->can('{$feature}.update'));",
                );
            }
        }
    }

    /**
     * Membership delivery: a channel Kinetix cannot send on means the
     * activation link is quietly handed back to the admin instead of reaching
     * the member — correct, but not what the config says is happening.
     */
    protected function checkMembershipDelivery(): void
    {
        if (! config('kinetix.membership.enabled', false)) {
            return;
        }

        $delivery     = (string) config('kinetix.membership.delivery', 'mail');
        $provisioning = (string) config('kinetix.membership.provisioning', 'activation');

        if ($delivery !== 'mail' || $provisioning !== 'activation') {
            $this->ok('Membership', "provisioning: {$provisioning}, delivery: {$delivery}");
        }

        if ($provisioning === 'direct' && ! config('kinetix.credentials.enabled', false)) {
            $this->error_(
                'Membership',
                'provisioning is "direct" but `credentials.enabled` is false — the temporary password an admin hands over never expires and is never forced to change',
                'Enable the Credentials module and publish --tag=kinetix-credentials-migrations.',
            );
        }

        if ($delivery !== 'sms') {
            return;
        }

        $channel      = (string) config('kinetix.membership.sms_channel', 'vonage');
        $notification = config('kinetix.membership.activation_notification');
        $class        = is_string($notification) && class_exists($notification)
            ? $notification
            : MemberActivationNotification::class;

        if (! method_exists($class, 'to'.Str::studly(class_basename($channel)))) {
            $this->error_(
                'Membership',
                'delivery is "sms" but the activation notification has no `to'.Str::studly(class_basename($channel)).'()` — every link is handed back to the admin instead of being sent',
                'Point `membership.activation_notification` at a subclass implementing your channel\'s message method; '
                .'`smsContent()` gives you the text.',
                [class_basename($class).' → channel "'.$channel.'"'],
            );
        }

        if ((string) config('kinetix.membership.identifier', 'email') !== 'phone') {
            $this->warn_(
                'Membership',
                'delivery is "sms" but members are provisioned by '.config('kinetix.membership.identifier', 'email')
                .' — anyone without a phone number on file gets their link handed back instead of texted',
                "Set `membership.identifier` to 'phone', or expect the manual fallback.",
            );
        }
    }

    /**
     * The password policy is enforced by a middleware Kinetix cannot install
     * for you and by columns it cannot assume exist. Get either wrong and the
     * policy is simply not applied — with nothing anywhere to say so.
     */
    protected function checkCredentials(): void
    {
        if (! config('kinetix.credentials.enabled', false)) {
            return;
        }

        $policy = app(PasswordPolicy::class);

        // Without the columns the observer stands down entirely, so nothing is
        // ever stamped and no password can expire.
        $missing = array_values(array_filter(
            ['password_changed_at', 'must_change_password'],
            static fn (string $column): bool => ! Schema::hasColumn('users', $column),
        ));

        if ($missing !== []) {
            $this->error_(
                'Credentials',
                'the users table is missing the password policy columns, so the policy is NOT enforced',
                'php artisan vendor:publish --tag=kinetix-credentials-migrations && php artisan migrate',
                $missing,
            );

            return;
        }

        $this->checkIdentity();

        $enforcing = $policy->expiryDays() !== null || $policy->historyDepth() > 0;

        if (! $enforcing) {
            $this->ok('Credentials', 'enabled, no password rules configured (expiry off, history off)');

            return;
        }

        $rules = [];

        if ($policy->expiryDays() !== null) {
            $rules[] = 'expires after '.$policy->expiryDays().' days';
        }

        if ($policy->historyDepth() > 0) {
            $rules[] = 'remembers '.$policy->historyDepth().' previous password(s)';
        }

        $this->ok('Credentials', implode(', ', $rules));

        // Expiry without the middleware is the silent one: everything looks
        // configured, and nobody is ever sent to change their password.
        if ($policy->expiryDays() !== null && ! $this->passwordMiddlewareIsApplied()) {
            $this->warn_(
                'Credentials',
                'passwords expire but the `kinetix.password` middleware is not in any route group — nobody is ever asked to change theirs',
                "Append it to your authenticated group: \$middleware->appendToGroup('web', "
                .'\Happones\Kinetix\Credentials\Middleware\EnsurePasswordIsCurrent::class);',
            );
        }

        if ((int) config('kinetix.credentials.passwords.history', 0) > PasswordPolicy::MAX_HISTORY) {
            $this->warn_(
                'Credentials',
                'passwords.history is capped at '.PasswordPolicy::MAX_HISTORY.' — each remembered password costs a deliberately slow hash comparison on every change',
            );
        }

        $this->checkCredentialProfiles($policy);
    }

    /**
     * Provider login that links on the email alone lets anyone who controls
     * a provider account with a victim's (unverified) address sign in as them.
     */
    protected function checkSocialLogin(): void
    {
        // The provider skips the module's routes without Socialite and only
        // logs it from console processes and locally.
        if (config('kinetix.connected_accounts.enabled', false) && ! class_exists(Socialite::class)) {
            $this->error_(
                'Connected accounts',
                'the module is enabled but laravel/socialite is not installed, so its routes are not registered',
                'Install it: composer require laravel/socialite',
            );

            return;
        }

        if (! config('kinetix.connected_accounts.enabled', false) || ! config('kinetix.connected_accounts.login_enabled', false)) {
            return;
        }

        if ((string) config('kinetix.connected_accounts.link_by_email', 'verified') !== 'always') {
            return;
        }

        $this->warn_(
            'Connected accounts',
            'provider sign-in links to any account with the same email, verified or not',
            "Set `connected_accounts.link_by_email` to 'verified' (the default) unless every provider you offer verifies emails.",
        );
    }

    /**
     * Credential profiles get the whole lifecycle, but only with their columns
     * on THEIR table, the history kept apart, and a guard that reaches the
     * change screen. Each gap fails silently for that profile's users.
     */
    protected function checkCredentialProfiles(PasswordPolicy $policy): void
    {
        $profiles = array_filter(
            (array) config('kinetix.credentials.profiles', []),
            static fn (mixed $settings, mixed $name): bool => is_string($name) && is_array($settings),
            ARRAY_FILTER_USE_BOTH,
        );

        if ($profiles === []) {
            return;
        }

        if ($policy->historyDepth() > 0
            && Schema::hasTable('kinetix_password_history')
            && ! Schema::hasColumn('kinetix_password_history', 'authenticatable_type')) {
            $this->warn_(
                'Credentials',
                'credential profiles share one password history (matched by id alone) until its type column exists',
                'php artisan vendor:publish --tag=kinetix-credentials-migrations && php artisan migrate',
            );
        }

        $expires = $policy->expiryDays()                                   !== null
            || config('kinetix.credentials.passwords.temporary_ttl_hours') !== null;

        foreach ($profiles as $name => $settings) {
            $model = $settings['user_model'] ?? null;

            if (is_string($model) && class_exists($model) && is_subclass_of($model, Model::class)) {
                $table   = (new $model)->getTable();
                $missing = array_values(array_filter(
                    ['password_changed_at', 'must_change_password'],
                    static fn (string $column): bool => ! Schema::hasColumn($table, $column),
                ));

                if ($missing !== []) {
                    $this->warn_(
                        'Credentials',
                        "profile \"{$name}\": the {$table} table is missing the password policy columns, so its users get no expiry or forced change",
                        'Add them in a migration: $table->kinetixPasswordColumns();',
                        $missing,
                    );
                }
            }

            if ($expires && ! is_string($settings['guard'] ?? null)) {
                $this->warn_(
                    'Credentials',
                    "profile \"{$name}\" declares no guard, so its users can't reach the change-password screen",
                    "Set credentials.profiles.{$name}.guard to the guard they log in with.",
                );
            }
        }
    }

    /**
     * Login identity: accepting a field the table doesn't carry means nobody
     * can ever sign in with it, and a `username` pattern that allows `@` lets
     * one person register another's email address as their username.
     */
    protected function checkIdentity(): void
    {
        $identity = app(IdentityResolver::class);
        $fields   = $identity->fields();

        if ($fields === ['email']) {
            return;
        }

        $this->ok('Credentials', 'login identity: '.implode(', ', $fields));

        $missing = array_values(array_filter(
            $fields,
            static fn (string $field): bool => ! Schema::hasColumn('users', $field),
        ));

        if ($missing !== []) {
            $this->error_(
                'Credentials',
                'the users table has no column for accepted login field(s) — nobody can sign in with them',
                'php artisan vendor:publish --tag=kinetix-identity-migrations && php artisan migrate',
                $missing,
            );
        }

        // `@` in a username is how one person registers another's email address
        // as their username; classification stops it being MATCHED, but the
        // collision should not be creatable in the first place.
        if (in_array('username', $fields, true)
            && in_array('email', $fields, true)
            && preg_match($identity->usernamePattern(), 'someone@example.com') === 1) {
            $this->warn_(
                'Credentials',
                'the username pattern accepts an email address, so a username can be registered that looks like someone else\'s email',
                'Exclude `@` from `credentials.identity.username_pattern` (the shipped default already does).',
            );
        }

        if (in_array('phone', $fields, true)
            && (string) config('kinetix.credentials.identity.phone_country', '') === '') {
            $this->warn_(
                'Credentials',
                'phone logins are accepted but no `identity.phone_country` is set — a number typed without a country code is stored as-is, so the same person can end up with two accounts',
                "Set it to the ISO country your staff dial from, e.g. 'MX'.",
            );
        }
    }

    /**
     * Whether any registered route actually runs the password middleware.
     */
    protected function passwordMiddlewareIsApplied(): bool
    {
        $router = app('router');

        foreach (Route::getRoutes()->getRoutes() as $route) {
            // Resolved: groups expand to their members and aliases to their
            // classes, so one appended to `web` (as the docs advise) counts.
            foreach ($router->gatherRouteMiddleware($route) as $middleware) {
                if (! is_string($middleware)) {
                    continue;
                }

                if ($middleware === 'kinetix.password'
                    || str_contains($middleware, 'EnsurePasswordIsCurrent')) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Entitlements compose four gating layers, and every way of getting one
     * wrong denies SILENTLY — which is the worst possible failure mode for
     * authorization UI (a button that simply never appears, with no error).
     */
    protected function checkEntitlements(): void
    {
        $registry     = app(EntitlementRegistry::class);
        $entitlements = $registry->all();

        if ($entitlements === []) {
            return;
        }

        $names = array_keys($entitlements);

        // The one that bites hardest: declarations exist and the server
        // enforces them, but the Inertia prop is never populated, so EVERY
        // `<KinetixEntitled>` on the frontend resolves to denied — the whole
        // UI quietly disappears with nothing in the logs.
        if (! config('kinetix.entitlements.enabled', false)) {
            $this->error_(
                'Entitlements',
                count($names).' entitlement(s) are declared but `kinetix.entitlements.enabled` is FALSE — '
                .'the `kinetix_entitlements` prop stays empty, so every <KinetixEntitled> / useKinetixEntitlement '
                .'check on the frontend silently denies',
                'Set KINETIX_ENTITLEMENTS_ENABLED=true (the server-side checks work either way — this is the prop).',
                $names,
            );
        } else {
            $this->ok('Entitlements', count($names).' declared', null, $names);
        }

        $this->checkEntitlementLayers($entitlements);
    }

    /**
     * Layers that can never pass: a `->plan()` with billing off is skipped
     * (fail-open, fine), but a `->permission()` naming an ability nothing
     * defines denies forever.
     *
     * @param array<string, Entitlement> $entitlements
     */
    protected function checkEntitlementLayers(array $entitlements): void
    {
        $registry = class_exists(PermissionRegistrar::class) && config('kinetix.permissions.enabled', false)
            ? app(PermissionRegistry::class)
            : null;

        $unknown  = [];
        $planless = [];

        foreach ($entitlements as $name => $entitlement) {
            $ability = $entitlement->permissionAbility();

            if ($ability !== null
                && ! Gate::has($ability)
                && ($registry === null || ! $registry->has($ability))) {
                $unknown[] = "{$name} → {$ability}";
            }

            if ($entitlement->usesPlanLayers() && ! config('kinetix.billing.enabled', false)) {
                $planless[] = $name;
            }
        }

        if ($unknown !== []) {
            $this->warn_(
                'Entitlements',
                'entitlement(s) require an ability that is neither registered with Kinetix nor defined on the Gate — they deny for everyone',
                'Register it (KinetixPermissions::feature(...)->ability(...) + php artisan kinetix:permissions:sync) or define it with Gate::define().',
                $unknown,
            );
        }

        if ($planless !== []) {
            $this->warn_(
                'Entitlements',
                'entitlement(s) declare a plan capability or usage limit while `kinetix.billing.enabled` is false — those layers are SKIPPED (fail open), so they gate nothing',
                'Enable billing, or drop the ->plan()/->limit() layer from the declaration.',
                $planless,
            );
        }
    }

    /**
     * Whether the feature's `{feature}.{ability}` permissions exist in the
     * spatie table. Unreachable DB (no migrations yet) counts as not synced.
     */
    protected function featureSynced(string $feature): bool
    {
        try {
            return Permission::query()
                ->where('name', 'like', $feature.'.%')
                ->exists();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Policy CRUD methods whose entire body is `return true;`.
     *
     * @return array<int, string>
     */
    protected function staticTruePolicyMethods(object $policy): array
    {
        $static = [];

        foreach (['viewAny', 'view', 'create', 'update', 'delete', 'restore', 'forceDelete'] as $ability) {
            if (! method_exists($policy, $ability)) {
                continue;
            }

            $method = new \ReflectionMethod($policy, $ability);
            $file   = $method->getFileName();

            if ($file === false || $method->getStartLine() === false || $method->getEndLine() === false) {
                continue;
            }

            $lines  = array_slice(file($file) ?: [], $method->getStartLine() - 1, $method->getEndLine() - $method->getStartLine() + 1);
            $source = implode('', $lines);

            $open  = strpos($source, '{');
            $close = strrpos($source, '}');

            if ($open === false || $close === false || $close <= $open) {
                continue;
            }

            $body = substr($source, $open + 1, $close - $open - 1);
            $body = (string) preg_replace('!/\*.*?\*/!s', '', $body);
            $body = (string) preg_replace('!//[^\n]*!', '', $body);
            $body = (string) preg_replace('/\s+/', '', $body);

            if ($body === 'returntrue;') {
                $static[] = $ability;
            }
        }

        return $static;
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

        $this->checkAssignableRoles(config('kinetix.membership.assignable_roles'));
        $this->checkMemberFields();
        $this->checkPendingProvisionsOfMembers();

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
     * `membership.fields` must name a MemberFields class, and its values need
     * the `fields` column of the provisions table.
     */
    protected function checkMemberFields(): void
    {
        $class = config('kinetix.membership.fields');

        if ($class === null || $class === '' || $class instanceof \Closure) {
            return;
        }

        if (! is_string($class) || ! is_subclass_of($class, MemberFields::class)) {
            $this->error_('Membership', 'membership.fields is not a MemberFields class',
                'Set it to the class-string of a class extending '.MemberFields::class.'. '
                .'Until then the members screen fails instead of dropping the fields silently.');

            return;
        }

        if (Schema::hasTable('kinetix_member_provisions') && ! Schema::hasColumn('kinetix_member_provisions', 'fields')) {
            $this->error_('Membership', 'member fields are configured but the provisions table has no `fields` column',
                'Run: php artisan vendor:publish --tag=kinetix-membership-migrations && php artisan migrate');

            return;
        }

        $this->ok('Membership', 'member fields configured');
    }

    /**
     * Pending provisions whose identifier belongs to someone who is already a
     * member of that team (any existing user, without teams). Before 0.275,
     * provisioning an active member reset their row to pending and dropped
     * its user, so revoking it later left their role in place. Kinetix never
     * guesses who such a row meant: the operator reviews them.
     */
    protected function checkPendingProvisionsOfMembers(): void
    {
        $userModel = (string) config('kinetix.membership.user_model', 'App\\Models\\User');

        if (! Schema::hasTable('kinetix_member_provisions') || ! class_exists($userModel) || ! is_subclass_of($userModel, Model::class)) {
            return;
        }

        $usersTable = (new $userModel)->getTable();
        $fields     = array_values(array_filter(
            ['email', 'username', 'phone'],
            static fn (string $field): bool => Schema::hasColumn('kinetix_member_provisions', $field) && Schema::hasColumn($usersTable, $field),
        ));

        if ($fields === []) {
            return;
        }

        $suspects = [];

        MemberProvision::query()
            ->where('status', MemberProvisionStatus::Pending)
            ->whereNull('user_id')
            ->lazyById(500)
            ->each(function (MemberProvision $provision) use ($userModel, $fields, &$suspects): void {
                foreach ($fields as $field) {
                    $value = $provision->getAttribute($field);

                    if (blank($value)) {
                        continue;
                    }

                    $user = $userModel::query()->where($field, $value)->first();

                    if ($user === null) {
                        return;
                    }

                    if ($provision->team_id !== null && ! KinetixTeams::userBelongsTo($user, $provision->team_id)) {
                        return;
                    }

                    $suspects[] = "#{$provision->getKey()} {$value}".($provision->team_id !== null ? " (team {$provision->team_id})" : '');

                    return;
                }
            });

        if ($suspects === []) {
            return;
        }

        $this->warn_(
            'Membership',
            count($suspects).' pending provision(s) name someone who is already a member',
            'Rows like these come from re-provisioning an active member before 0.275: revoking them now removes '
            .'nothing, so that member keeps their role. Review each one — remove the member from their own row, '
            .'or cancel the invitation if it was a mistake.',
            $suspects,
        );
    }

    /**
     * The member role picker: linked to the Roles screen (null / the
     * AssignableRoles resolver), a callback, or a fixed list — and a fixed
     * list naming roles the Roles screen doesn't have, or the owner's
     * super-admin role, is worth a warning.
     */
    protected function checkAssignableRoles(mixed $roles): void
    {
        if ($roles === null || $roles === AssignableRoles::class) {
            $this->ok('Membership', 'assignable roles follow the Roles screen');

            return;
        }

        if (ConfigCallback::resolve($roles) !== null) {
            $this->ok('Membership', 'assignable roles come from a callback');

            return;
        }

        $names = array_values(array_map(
            static fn (mixed $role): string => is_array($role) ? (string) ($role['name'] ?? '') : (string) $role,
            (array) $roles,
        ));

        if ($names === []) {
            $this->warn_('Membership', 'assignable_roles is empty',
                'No role can be assigned, so every provision attempt returns 422. '
                .'Set it to null to offer the roles from the Roles screen.');

            return;
        }

        $reserved = array_values(array_intersect($names, SuperAdmin::protectedRoles()));

        if ($reserved !== []) {
            $this->warn_('Membership', 'assignable_roles names a protected role',
                'The super-admin role belongs to the team owner and is never offered to members; '
                .'remove it from the list.', $reserved);
        }

        $roleClass = config('permission.models.role', Role::class);

        // App-assigned roles aren't Roles-screen rows; nothing to cross-check.
        if (ConfigCallback::resolve(config('kinetix.membership.assign_role')) !== null) {
            $this->ok('Membership', 'fixed list of '.count($names).' app-assigned role(s)');

            return;
        }

        if (! config('kinetix.permissions.enabled', false) || ! class_exists($roleClass)) {
            return;
        }

        try {
            $existing = $roleClass::query()->whereIn('name', $names)->pluck('name')->all();
        } catch (QueryException) {
            return;
        }

        $missing = array_values(array_diff($names, $existing, $reserved));

        if ($missing !== []) {
            $this->warn_('Membership', 'assignable_roles names roles that do not exist',
                'A member given one of these fails at activation. Create them in the Roles screen, '
                .'or set assignable_roles to null so the picker offers exactly the roles that exist.',
                $missing);

            return;
        }

        $this->ok('Membership', 'fixed list of '.count($names).' assignable role(s)',
            'Roles created later in the Roles screen will not appear in the member picker; '
            .'set assignable_roles to null to keep them in sync.');
    }

    /**
     * `tokens.team_column` names a column on the Sanctum tokens table: when it
     * is missing, every token create fails and no token is scoped to a team.
     */
    protected function checkTokenTeamColumn(): void
    {
        $this->checkTokenSealCollisions();

        $column = config('kinetix.tokens.team_column');

        if (! config('kinetix.tokens.enabled', false) || ! is_string($column) || $column === '') {
            return;
        }

        $table = class_exists(Sanctum::class)
            ? (new (Sanctum::$personalAccessTokenModel))->getTable()
            : 'personal_access_tokens';

        try {
            if (! Schema::hasTable($table)) {
                return;
            }

            if (Schema::hasColumn($table, $column)) {
                $this->ok('Tokens', "tokens are scoped to a team through {$table}.{$column}");

                return;
            }
        } catch (QueryException) {
            return;
        }

        $this->error_('Tokens', "tokens.team_column is '{$column}' but {$table} has no such column",
            'Creating a token fails and none is tied to a team. Add the column in a migration: '
            ."\$table->foreignId('{$column}')->nullable()->index();", [$table]);
    }

    /**
     * A token scope that starts with `tokens.team_ability` or equals a stamped
     * ability cannot be granted (the create form refuses it) and would be read
     * as a team seal; and, once sealing is on, tokens minted before it are
     * counted: they pass `kinetix.token-team` without a team.
     */
    protected function checkTokenSealCollisions(): void
    {
        if (! config('kinetix.tokens.enabled', false)) {
            return;
        }

        $colliding = KinetixTokens::collidingScopes();

        if ($colliding !== []) {
            $this->error_('Tokens', count($colliding).' token scope(s) collide with tokens.team_ability / tokens.stamp_abilities',
                'Rename the scope, or change the prefix or the stamp: a colliding scope is refused on create '
                .'and ignored when reading a token\'s team.', $colliding);
        }

        $prefix = KinetixTokens::sealPrefix();

        if ($prefix === null || ! config('kinetix.teams', false)) {
            return;
        }

        $unsealed = 0;

        try {
            $table = class_exists(Sanctum::class)
                ? (new (Sanctum::$personalAccessTokenModel))->getTable()
                : 'personal_access_tokens';

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'abilities')) {
                return;
            }

            // Counted in PHP: a LIKE would have to escape wildcards and the
            // JSON-escaped slashes of the stored list.
            $live = static fn ($query) => $query->whereNull('expires_at')->orWhere('expires_at', '>', now());

            foreach (DB::table($table)->where($live)->select('id', 'abilities')->lazyById() as $row) {
                $abilities = json_decode((string) $row->abilities, true);

                $sealed = is_array($abilities) && array_filter(
                    $abilities,
                    static fn (mixed $ability): bool => is_string($ability) && str_starts_with($ability, $prefix),
                ) !== [];

                $unsealed += $sealed ? 0 : 1;
            }
        } catch (QueryException) {
            return;
        }

        if ($unsealed > 0) {
            $this->warn_('Tokens', "{$unsealed} token(s) carry no team seal",
                'They are listed in every team and pass kinetix.token-team without a team; revoke and re-issue them, '
                .'or set tokens.require_team to refuse them.');
        }
    }

    protected function checkChannelPhoneCountries(): void
    {
        $configured = config('kinetix.channels.phone_countries');

        if (! config('kinetix.channels.enabled', false) || $configured === null) {
            return;
        }

        $invalid = is_array($configured)
            ? array_values(array_filter(
                array_map(static fn (mixed $code): string => is_string($code) ? $code : get_debug_type($code), $configured),
                static fn (string $code): bool => DialCodes::for($code) === null,
            ))
            : [get_debug_type($configured)];

        if ($invalid !== [] || $configured === []) {
            $this->warn_('Channels', 'channels.phone_countries is empty or holds values that are not ISO alpha-2 codes, so numbers cannot be linked from them',
                'Use null for every country, or a list such as [\'MX\'].', $invalid);

            return;
        }

        $this->ok('Channels', 'phone numbers limited to '.implode(', ', $configured));
    }

    protected function checkNotificationPreferences(): void
    {
        if (! config('kinetix.notification_preferences.enabled', false)) {
            return;
        }

        $publish = 'php artisan vendor:publish --tag=kinetix-notification-preferences-migrations && php artisan migrate';

        if (! Schema::hasTable('kinetix_notification_preferences')) {
            $this->error_('Notification preferences', 'enabled but the preferences table is missing', $publish);

            return;
        }

        if (! Schema::hasColumn('kinetix_notification_preferences', 'notifiable_type')) {
            $this->warn_(
                'Notification preferences',
                'notifiable models share one row per id (a Client #1 reads a User #1\'s choices) until its type column exists',
                $publish,
            );
        } elseif (! collect(Schema::getIndexes('kinetix_notification_preferences'))->contains(
            static fn (array $index): bool => $index['unique'] && $index['columns'] === ['user_id', 'notifiable_type'],
        )) {
            $this->warn_(
                'Notification preferences',
                'the table has no unique key on user_id + notifiable_type, so two saves at once can split a user\'s choices across rows',
                $publish,
            );
        }

        $types = app(NotificationTypeRegistry::class)->all();

        if (array_key_exists('kinetix.data-exports', $types)) {
            $this->warn_(
                'Notification preferences',
                'kinetix.data-exports is registered, but personal-data exports are always delivered, so its switches do nothing',
                'Remove it from kinetix.notification_preferences.types.',
            );
        }

        if ($types === []) {
            $this->warn_(
                'Notification preferences',
                'no notification types registered, so the matrix is empty and nothing can be turned off',
                'List them in kinetix.notification_preferences.types, or call KinetixNotificationPreferences::types([...]) in a provider.',
            );

            return;
        }

        $this->ok('Notification preferences', count($types).' type(s) registered');
    }

    protected function checkNotificationRouting(): void
    {
        if (! config('kinetix.notification_routing.enabled', false)) {
            return;
        }

        $publish = 'php artisan vendor:publish --tag=kinetix-notification-routing-migrations && php artisan migrate';

        foreach (['kinetix_notification_routes', 'kinetix_notification_groups', 'kinetix_notification_group_members'] as $table) {
            if (! Schema::hasTable($table)) {
                $this->error_('Notification routing', "enabled but the {$table} table is missing", $publish);

                return;
            }
        }

        $this->checkRoutingPeople();

        $registry = app(NotificationTypeRegistry::class);
        $manager  = app(NotificationPreferenceManager::class);
        $types    = array_filter(array_keys($registry->all()), static fn (string $type): bool => ! str_starts_with($type, 'kinetix.'));

        if ($types === []) {
            $this->warn_('Notification routing', 'no notification types registered, so there is nothing to route',
                'Register them with KinetixNotificationPreferences::types([...]) in a provider.');

            return;
        }

        $problems = [];

        foreach ($types as $type) {
            $ability = $registry->requirementOf($type);

            if ($ability !== null && ! static::abilityIsKnown($ability)) {
                $problems[] = "{$type}: requires('{$ability}') is not a defined ability, so nobody is eligible";
            }

            $stray = array_diff($registry->mandatoryOf($type), $manager->channelsOf($type));

            if ($stray !== []) {
                $problems[] = "{$type}: mandatory channel(s) ".implode(', ', $stray).' are not ones the type is delivered on';
            }
        }

        if ($problems !== []) {
            $this->warn_('Notification routing', count($problems).' type(s) have a routing declaration that does nothing',
                'Define the ability with Gate::define() or a policy, and keep mandatory() to the type\'s deliverOn() channels.', $problems);
        }

        if (config('queue.default') === 'sync') {
            $this->warn_('Notification routing', 'the queue connection is sync, so a routed notification is delivered inside the request that sends it',
                'Use a real queue connection (and set kinetix.notification_routing.queue) so a large audience does not slow the request.');
        }

        if (! config('kinetix.notification_preferences.enabled', false)) {
            $this->warn_('Notification routing', 'Notification Preferences is off, so users cannot opt out and mandatory() has no effect',
                'Set kinetix.notification_preferences.enabled to true if people should choose their channels.');
        }

        $this->ok('Notification routing', count($types).' routable type(s)');
    }

    /**
     * With teams on, routing reaches only the members it can resolve: an
     * app that gives neither a callable nor a team model with a members
     * relation would notify nobody.
     */
    protected function checkRoutingPeople(): void
    {
        if (! config('kinetix.teams', false) || ConfigCallback::resolve(config('kinetix.notification_routing.members')) !== null) {
            return;
        }

        $relation  = (string) config('kinetix.notification_routing.members_relation', 'members');
        $teamModel = HostKeys::teamModel();

        if ($teamModel === null || ! method_exists($teamModel, $relation)) {
            $this->error_('Notification routing', 'teams are on but the team members cannot be resolved, so no notification reaches anybody',
                "Set kinetix.notification_routing.members to a callable array [Class::class, 'method'] receiving the team key and returning its users, or give your team model a '{$relation}' relation (kinetix.notification_routing.members_relation).");
        }
    }

    /**
     * Whether an ability exists: a Gate definition, a Kinetix registered
     * ability, or a spatie permission of the guard (granted through
     * Gate::before, so Gate::has() cannot see it).
     */
    protected static function abilityIsKnown(string $ability): bool
    {
        if (Gate::has($ability) || app(PermissionRegistry::class)->featureOf($ability) !== null) {
            return true;
        }

        if (! class_exists(PermissionRegistrar::class)) {
            return false;
        }

        try {
            /** @var class-string<Model> $model */
            $model = config('permission.models.permission', Permission::class);

            return Schema::hasTable((new $model)->getTable())
                && $model::query()->where('name', $ability)->where('guard_name', (string) config('kinetix.permissions.guard', 'web'))->exists();
        } catch (QueryException) {
            return false;
        }
    }

    /**
     * Team billing where nobody can change the plan: the billing endpoints ask
     * for `billing.manage` (or ownership), and a setup that grants it to no
     * one answers 403 to every subscribe, cancel and card change.
     */
    protected function checkBilling(): void
    {
        if (! config('kinetix.billing.enabled', false) || config('kinetix.billing.authorize') !== null) {
            return;
        }

        if (! KinetixTeams::enabledFor('billing') && config('kinetix.tenancy.subdomain') === null) {
            return;
        }

        if (Gate::has('billing.manage')) {
            return;
        }

        if (config('kinetix.permissions.enabled', false)) {
            if (TeamOwner::enabled() && TeamOwner::grants('billing.manage')) {
                return;
            }

            if (! class_exists(PermissionRegistrar::class) || ! static::permissionTablesExist()) {
                return;
            }

            /** @var class-string<Model> $roleClass */
            $roleClass = config('permission.models.role', Role::class);

            $granted = $roleClass::query()
                ->whereHas('permissions', static function ($permissions): void {
                    $permissions->where($permissions->getModel()->qualifyColumn('name'), 'billing.manage');
                })
                ->exists();

            if (! $granted) {
                $this->warn_('Billing', 'team billing is on but no role grants billing.manage',
                    'Every member gets 403 on subscribe, payment methods, cancel and resume. Run '
                    .'kinetix:permissions:sync and grant billing.manage to a role, turn on the owner bypass for it '
                    ."(permissions.owner_bypass), or set billing.authorize to [Class::class, 'method'].");
            }

            return;
        }

        $userModel = (string) HostKeys::userModelClass();

        if (class_exists($userModel) && method_exists($userModel, 'ownsTeam')) {
            return;
        }

        $this->warn_('Billing', 'team billing is on but nobody can manage it',
            "Without the Permissions module, billing.manage needs {$userModel}::ownsTeam(\$team), which the user "
            ."model lacks. Add ownsTeam(), enable the Permissions module, or set billing.authorize to [Class::class, 'method'].");
    }

    /**
     * A closure in a config file makes `php artisan config:cache` abort, so the
     * app cannot deploy with a cached config. Catch it here rather than in CI.
     */
    protected function checkConfigCallbacks(): void
    {
        $options = [
            'kinetix.permissions.owner_bypass',
            'kinetix.permissions.owner_bypass.using',
            'kinetix.membership.assignable_roles',
            'kinetix.membership.attach_member',
            'kinetix.membership.detach_member',
            'kinetix.membership.assign_role',
            'kinetix.membership.fields',
            'kinetix.notification_routing.members',
            'kinetix.billing.authorize',
            'kinetix.billing.resolve_billable',
            'kinetix.connected_accounts.email_verified',
            'kinetix.connected_accounts.two_factor_challenge',
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
     * Keys the package's config has and the app's published copy lacks.
     * Laravel's `mergeConfigFrom` only fills in whole top-level sections: a
     * new key inside a section the app already defines (`inbox.mirror_sent`,
     * `billing.lock_plans_during_trial`) never arrives, silently.
     */
    protected function checkConfigKeys(): void
    {
        $published = config_path('kinetix.php');

        if (! File::exists($published)) {
            return;
        }

        $app     = require $published;
        $package = require __DIR__.'/../../config/kinetix.php';

        if (! is_array($app) || ! is_array($package)) {
            return;
        }

        $missing = [];

        foreach ($package as $section => $defaults) {
            // A whole missing section is merged in by Laravel.
            if (is_array($defaults) && is_array($app[$section] ?? null)) {
                $missing = [...$missing, ...static::missingKeys($defaults, $app[$section], (string) $section)];
            }
        }

        if ($missing === []) {
            $this->ok('Config', 'config/kinetix.php has every key the package knows');

            return;
        }

        $this->warn_('Config', count($missing).' key(s) of the package are missing from config/kinetix.php',
            'Laravel only merges whole top-level sections, so these use their built-in defaults and never show '
            .'up for you to set. Copy them from vendor/happones/kinetix/config/kinetix.php.', array_slice($missing, 0, 25));
    }

    /**
     * Dot paths of `$defaults` missing from `$app`, walking nested sections
     * (not lists, which are values). An app value that is a non-empty list
     * where the package now ships a map is an older, still-supported shape
     * (`permissions.owner_bypass => [Class::class, 'method']`), not a
     * partial copy, so it is not walked.
     *
     * @param  array<array-key, mixed> $defaults
     * @param  array<array-key, mixed> $app
     * @return list<string>
     */
    protected static function missingKeys(array $defaults, array $app, string $prefix): array
    {
        if (array_is_list($defaults)) {
            return [];
        }

        $missing = [];

        foreach ($defaults as $key => $value) {
            $path = "{$prefix}.{$key}";

            if (! array_key_exists($key, $app)) {
                $missing[] = $path;
            } elseif (! in_array($path, self::OPTIONAL_KEY_SECTIONS, true) && static::walksInto($value, $app[$key])) {
                $missing = [...$missing, ...static::missingKeys($value, $app[$key], $path)];
            }
        }

        return $missing;
    }

    /**
     * Whether a nested default section should be walked against the app's
     * value.
     *
     * @phpstan-assert-if-true array<array-key, mixed> $default
     * @phpstan-assert-if-true array<array-key, mixed> $app
     */
    protected static function walksInto(mixed $default, mixed $app): bool
    {
        if (! is_array($default) || $default === [] || ! is_array($app)) {
            return false;
        }

        if ($app !== [] && array_is_list($app) && ! array_is_list($default)) {
            return false;
        }

        return ! static::isChosenMap($default, $app);
    }

    /**
     * A nested map of plain values the app already set (`locale.locales`:
     * code => label) is a choice, not settings: leaving entries out is
     * the point.
     *
     * @param array<array-key, mixed> $defaults
     * @param array<array-key, mixed> $app
     */
    protected static function isChosenMap(array $defaults, array $app): bool
    {
        return $app !== [] && array_filter($defaults, static fn (mixed $value): bool => ! is_scalar($value) && $value !== null) === [];
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
            ['mail_templates', 'kinetix_mail_templates', 'kinetix-mail-templates-migrations'],
            ['announcements', 'kinetix_announcements', 'kinetix-announcements-migrations'],
            // Read state is per (user, team): without the column, reading one
            // team's feed clears that user's badge in every other team.
            ['announcements', 'kinetix_announcement_views', 'kinetix-announcements-migrations'],
            ['reports_center', 'kinetix_report_schedules', 'kinetix-reports-center-migrations'],
            ['api_logs', 'kinetix_api_logs', 'kinetix-api-logs-migrations'],
        ] as [$module, $table, $tag]) {
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
     * The composite indexes the listing queries need, per enabled module:
     * without them every page of a growing log or feed sorts the whole
     * tenant's rows. Table-driven like {@see checkTenantColumns()}; an index
     * counts when it STARTS with these columns in this order, whatever its
     * name.
     */
    protected function checkIndexes(): void
    {
        $missing = [];

        foreach ([
            ['activity', 'kinetix_activity', ['team_id', 'created_at'], 'kinetix-activity-migrations'],
            ['api_logs', 'kinetix_api_logs', ['team_id', 'created_at'], 'kinetix-api-logs-migrations'],
            ['webhooks', 'kinetix_webhook_logs', ['webhook_endpoint_id', 'created_at'], 'kinetix-webhooks-migrations'],
            ['reports_center', 'kinetix_report_runs', ['team_id', 'created_at'], 'kinetix-reports-center-migrations'],
            ['inbox', 'kinetix_conversations', ['team_id', 'archived_at', 'last_message_at'], 'kinetix-inbox-migrations'],
            ['notifications', 'notifications', ['notifiable_type', 'notifiable_id', 'created_at'], 'kinetix-notifications-migrations'],
        ] as [$module, $table, $columns, $tag]) {
            $enabled = $module === 'notifications'
                ? config('kinetix.notifications.database', false)
                : config("kinetix.{$module}.enabled", false);

            if (! $enabled) {
                continue;
            }

            try {
                if (! Schema::hasTable($table) || static::hasIndexStartingWith($table, $columns)) {
                    continue;
                }
            } catch (QueryException) {
                continue;
            }

            $missing[] = "{$table} (".implode(', ', $columns).') — vendor:publish --tag='.$tag;
        }

        if ($missing === []) {
            return;
        }

        $this->warn_('Indexes', count($missing).' table(s) lack the index their listing query uses',
            'Each page of the feed sorts every row of the tenant without it. Publish the tag, then run migrate '
            .'(idempotent; on a very large table, in a maintenance window).', $missing);
    }

    /**
     * Webhook secrets at rest: the outgoing endpoints' signing secrets and
     * the channel lines' provider secrets.
     */
    protected function checkWebhookSecrets(): void
    {
        if (config('kinetix.webhooks.enabled', false)) {
            $this->checkSecretsAtRest(new WebhookEndpoint, 'secret', 'Webhooks', [
                'narrow'        => 'the secret column is too small for encrypted secrets, so they are stored in plaintext',
                'tag'           => 'kinetix-webhooks-migrations',
                'plaintext'     => 'endpoint secret(s) stored in plaintext',
                'undecryptable' => 'Deliveries to those endpoints fail. Restore the previous key (APP_PREVIOUS_KEYS), or rotate those endpoints\' secrets.',
                'ok'            => 'endpoint secrets are encrypted at rest',
            ]);
        }

        if (config('kinetix.channels.enabled', false)) {
            $this->checkSecretsAtRest(new Channel, 'webhook_secret', 'Channels', [
                'narrow'        => 'the webhook_secret column is too small for encrypted secrets, so they are stored in plaintext',
                'tag'           => 'kinetix-channels-migrations',
                'plaintext'     => 'line webhook secret(s) stored in plaintext',
                'undecryptable' => 'Those lines\' provider calls fail to authenticate. Restore the previous key (APP_PREVIOUS_KEYS), or re-create those lines.',
                'ok'            => 'line webhook secrets are encrypted at rest',
            ]);
        }
    }

    /**
     * One table's secrets at rest: whether the column can hold them
     * encrypted, how many are still plaintext, and whether every encrypted
     * one decrypts with the current app key.
     *
     * @param array{narrow: string, tag: string, plaintext: string, undecryptable: string, ok: string} $say
     */
    protected function checkSecretsAtRest(Model $model, string $column, string $section, array $say): void
    {
        try {
            if (! Schema::hasTable($model->getTable())) {
                return;
            }

            $wide = EncryptedWithPlaintextFallback::columnHoldsCiphertext($model->getConnectionName(), $model->getTable(), $column);

            $plaintext     = 0;
            $undecryptable = 0;

            foreach ($model->newQueryWithoutScopes()->toBase()->select([$model->getKeyName(), $column])->lazyById(200, $model->getKeyName()) as $row) {
                $raw = $row->{$column} ?? null;

                if (! is_string($raw) || $raw === '') {
                    continue;
                }

                if (! EncryptedWithPlaintextFallback::isEncryptedPayload($raw)) {
                    $plaintext++;

                    continue;
                }

                try {
                    Crypt::decryptString($raw);
                } catch (DecryptException) {
                    $undecryptable++;
                }
            }
        } catch (QueryException) {
            return;
        }

        if (! $wide) {
            $this->warn_($section, $say['narrow'], "php artisan vendor:publish --tag={$say['tag']} && php artisan migrate");
        }

        if ($plaintext > 0) {
            $this->warn_($section, "{$plaintext} {$say['plaintext']}",
                $wide ? 'php artisan kinetix:webhooks:encrypt-secrets' : 'Widen the column first (see above), then run php artisan kinetix:webhooks:encrypt-secrets');
        }

        if ($undecryptable > 0) {
            $this->error_($section, "{$undecryptable} secret(s) can't be decrypted with the current APP_KEY", $say['undecryptable']);
        }

        if ($wide && $plaintext === 0 && $undecryptable === 0) {
            $this->ok($section, $say['ok']);
        }
    }

    /**
     * Confidential values not sealed to their row: v1 envelopes (written
     * before row binding) and v2 ones bound to their column only (rows
     * written without model events: `createQuietly()`, `saveQuietly()`,
     * `withoutEvents()`). Either can be copied to another row unnoticed.
     * Looks at the models that use `HasConfidentialAttributes` (those under
     * app/Models are loaded first).
     */
    protected function checkConfidentialEnvelopes(): void
    {
        if (! config('kinetix.confidential.enabled', false)) {
            return;
        }

        $unbound = [];

        foreach ($this->confidentialModels() as $class) {
            try {
                $model   = new $class;
                $columns = array_values(array_filter(
                    array_keys($model->getCasts()),
                    static fn (string $column): bool => ConfidentialCast::appliesTo($model, $column),
                ));

                if ($columns === [] || ! Schema::connection($model->getConnectionName())->hasTable($model->getTable())) {
                    continue;
                }

                foreach ($columns as $column) {
                    $legacy = $model->newQueryWithoutScopes()->toBase()->where($column, 'like', '{"v":1,%')->count();
                    $loose  = $model->newQueryWithoutScopes()->toBase()
                        ->where($column, 'like', '{"v":2,%')
                        ->where($column, 'like', '%"r":null}')
                        ->count();

                    if ($legacy + $loose > 0) {
                        $unbound[] = "{$class}::{$column} — {$legacy} v1, {$loose} column-bound";
                    }
                }
            } catch (Throwable) {
                continue;
            }
        }

        if ($unbound === []) {
            return;
        }

        $this->warn_('Confidential', count($unbound).' confidential column(s) hold values not sealed to their row',
            'A value copied from another row would still read. Run php artisan kinetix:confidential:encrypt-existing <Model> '
            .'for each, then set confidential.legacy_envelopes to false.', $unbound);
    }

    /**
     * The loaded model classes that use `HasConfidentialAttributes`, after
     * loading the app's own `app/Models`.
     *
     * @return list<class-string<Model>>
     */
    protected function confidentialModels(): array
    {
        $directory = app_path('Models');

        if (File::isDirectory($directory)) {
            foreach (File::allFiles($directory) as $file) {
                $relative = str_replace(['/', '.php'], ['\\', ''], $file->getRelativePathname());
                class_exists($this->laravel->getNamespace().'Models\\'.$relative);
            }
        }

        $models = [];

        foreach (get_declared_classes() as $class) {
            if (is_subclass_of($class, Model::class) && in_array(HasConfidentialAttributes::class, class_uses_recursive($class), true) && ! (new \ReflectionClass($class))->isAbstract()) {
                $models[] = $class;
            }
        }

        return $models;
    }

    /**
     * The rate limits of the sensitive endpoints ({@see KinetixThrottle}): a
     * value that isn't `attempts,minutes` (or off) falls back to the default.
     */
    protected function checkThrottles(): void
    {
        $malformed = [];

        foreach (KinetixThrottle::LIMITERS as [$key, $default]) {
            $value = config("kinetix.{$key}", $default);

            if ($value === false || $value === null || $value === '') {
                continue;
            }

            if (KinetixThrottle::strictParse($value) === null) {
                $malformed[] = "kinetix.{$key} = ".(is_scalar($value) ? var_export($value, true) : get_debug_type($value))." (using {$default})";
            }
        }

        if ($malformed === []) {
            return;
        }

        $this->warn_('Throttles', count($malformed).' rate limit(s) are not "attempts,minutes"',
            'Each falls back to its default. Use a string like "5,1", or false to turn the limit off.', $malformed);
    }

    /**
     * The lookup concurrency cap needs a cache store that can lock and share
     * state between workers; the statement budget only exists on MySQL,
     * MariaDB and PostgreSQL, and is read from the DEFAULT connection (the
     * one lookups on your models use unless they name another).
     */
    protected function checkSearchLimits(): void
    {
        $concurrency = KinetixLookupSlots::configured();
        $timeout     = QueryBudget::configured();

        if ($concurrency === null && $timeout === null) {
            return;
        }

        if ($concurrency !== null) {
            $store = Cache::getStore();

            if (! $store instanceof LockProvider) {
                $this->warn_('Search limits', 'kinetix.search.concurrency is set, but the cache store cannot lock',
                    'The cap does nothing without locks. Use a store that supports them (Redis recommended).');
            } elseif ($store instanceof ArrayStore) {
                $this->warn_('Search limits', 'kinetix.search.concurrency runs on the array cache store',
                    'The array store lives in one process, so under PHP-FPM every request sees free slots and the cap does nothing. Use Redis.');
            } elseif ($store instanceof DatabaseStore) {
                $this->warn_('Search limits', 'kinetix.search.concurrency runs on the database cache store',
                    'Every lookup then takes and frees database locks; Redis is a far better fit.');
            } else {
                $this->ok('Search limits', "{$concurrency} concurrent lookup(s) per user");
            }
        }

        if ($timeout === null) {
            return;
        }

        $name   = (string) config('database.default');
        $config = config("database.connections.{$name}");
        $config = is_array($config) ? $config : [];
        $driver = (string) ($config['driver'] ?? '');

        if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
            $this->warn_('Search limits', "kinetix.search.timeout is set, but the default connection ({$name}) is {$driver}, which has no statement timeout",
                'The budget only applies to MySQL, MariaDB and PostgreSQL; here lookups run without one.');

            return;
        }

        if (isset($config['read']) || isset($config['write'])) {
            $this->warn_('Search limits', "kinetix.search.timeout is set on a read/write split connection ({$name})",
                $driver === 'pgsql'
                    ? 'PostgreSQL can only limit a statement inside a transaction, which runs on the write connection: budgeted lookups go to the primary.'
                    : 'The limit is set on the read connection the lookup uses; if that is a pooled proxy (ProxySQL, RDS Proxy) the session may not stick, so verify the timeout fires.');
        }
    }

    /**
     * The table query timeout needs an engine that can cancel a statement,
     * and the cached totals need a store that is shared between workers.
     */
    protected function checkTableLimits(): void
    {
        $timeout = config('kinetix.tables.query_timeout');

        if (is_numeric($timeout) && (int) $timeout >= 1) {
            $name   = (string) config('database.default');
            $config = config("database.connections.{$name}");
            $config = is_array($config) ? $config : [];
            $driver = (string) ($config['driver'] ?? '');

            if (! in_array($driver, ['mysql', 'mariadb', 'pgsql'], true)) {
                $this->warn_('Table limits', "kinetix.tables.query_timeout is set, but the default connection ({$name}) is {$driver}, which has no statement timeout",
                    'The budget only applies to MySQL, MariaDB and PostgreSQL; here tables query without one.');
            } elseif ($driver === 'pgsql' && (isset($config['read']) || isset($config['write']))) {
                $this->warn_('Table limits', "kinetix.tables.query_timeout is set on a read/write split connection ({$name})",
                    'PostgreSQL can only limit a statement inside a transaction, which runs on the write connection: budgeted table queries go to the primary.');
            } else {
                $this->ok('Table limits', "{$timeout} ms per table statement");
            }
        }

        $store = config('kinetix.tables.cached_total.store');

        if (is_string($store) && $store !== '' && ! app()->environment('testing')) {
            try {
                $isArray = Cache::store($store)->getStore() instanceof ArrayStore;
            } catch (Throwable) {
                $isArray = false;
            }

            if ($isArray) {
                $this->warn_('Table limits', "kinetix.tables.cached_total.store ({$store}) is the array cache store",
                    'The array store lives in one process, so under PHP-FPM a cached total is never reused. Use Redis or another shared store.');
            }
        }
    }

    /**
     * @param list<string> $columns
     */
    protected static function hasIndexStartingWith(string $table, array $columns): bool
    {
        $expected = array_map('strtolower', $columns);

        foreach (Schema::getIndexes($table) as $index) {
            $indexed = array_map(static fn (mixed $column): string => strtolower((string) $column), (array) ($index['columns'] ?? []));

            if (array_slice($indexed, 0, count($expected)) === $expected) {
                return true;
            }
        }

        return false;
    }

    /**
     * Props listed in `shared_props.once` that are never kept: they change
     * on their own or carry security state, so the listing is ignored.
     */
    protected function checkSharedProps(): void
    {
        $ignored = KinetixSharedProps::ignored();

        if ($ignored === []) {
            return;
        }

        $this->warn_('Shared props', count($ignored).' prop(s) in `shared_props.once` are never kept between visits',
            'They change on their own or carry security state, so Kinetix sends them on every visit anyway. '
            .'Remove them from the list.', $ignored);
    }

    /**
     * The `kinetix:optimize` discovery manifest: stale in development, and
     * blind to roots added after it was written (those are scanned).
     */
    protected function checkDiscoveryManifest(): void
    {
        if (! DiscoveryManifest::exists()) {
            return;
        }

        if ($this->laravel->environment('local')) {
            $this->warn_('Discovery', 'the discovery manifest exists in the local environment',
                'New Resources, Spotlight sources and reports are not discovered until you run '
                .'php artisan optimize:clear (or kinetix:optimize-clear).');
        }

        $uncovered = [];

        foreach ([
            [PermissionRegistry::class, Resource::class],
            [SpotlightRegistry::class, SpotlightSource::class],
            [ReportsCenterRegistry::class, Report::class],
        ] as [$registry, $parent]) {
            foreach ($this->laravel->make($registry)->discoveryRoots() as [$directory, $namespace]) {
                if (! DiscoveryManifest::covers($directory, $namespace, $parent)) {
                    $uncovered[] = "{$directory} ({$namespace})";
                }
            }
        }

        if ($uncovered !== []) {
            $this->ok('Discovery', count($uncovered).' discovery root(s) are not in the manifest and are scanned per request',
                'Run php artisan kinetix:optimize again to cache them.', $uncovered);
        }
    }

    /**
     * `user_id` / `team_id` columns typed for a different key than the app's
     * — what `auto` detection before 0.219.15 left behind for a User/Team
     * that used `HasUlids` only for a public column: a char(26) holding a
     * number, joined against an integer key.
     */
    protected function checkHostKeyColumns(): void
    {
        $mismatched = HostKeyColumns::mismatched();

        if ($mismatched === []) {
            return;
        }

        $this->error_('Database', count($mismatched).' Kinetix key column(s) don\'t match the app\'s keys',
            'Created by an older key detection. php artisan kinetix:fix-key-columns writes the migration that converts them.',
            array_map(
                static fn (array $c): string => "{$c['table']}.{$c['column']} is {$c['actual']}; the {$c['kind']} key is {$c['expected']}",
                $mismatched,
            ));
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

    /**
     * Published files with a supported extension point, and the hint that names
     * it — so `doctor` never tells you to "use config" for a file that has no
     * config key.
     *
     * @var array<string, string>
     */
    protected const DRIFT_HATCHES = [
        'useKinetixIcons.ts' => 'For useKinetixIcons.ts specifically: call registerIcons({ \'my-name\': MyIcon }) '
            .'once from resources/js/app.ts instead — a file Kinetix never publishes, so an upgrade cannot drop it.',
    ];

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

        $hint = 'kinetix:upgrade re-publishes with --force on every composer install, so these edits will be lost. '
            .'Move the change into a wrapper, a slot, or config.';

        // Generic advice is useless when the file has a PURPOSE-BUILT hatch,
        // so name it. Patching the icon map is the common case: it used to be
        // the only way to declare an icon Kinetix doesn't ship.
        foreach (static::DRIFT_HATCHES as $needle => $advice) {
            if (array_filter($drifted, fn (string $file): bool => str_contains($file, $needle)) !== []) {
                $hint .= ' '.$advice;
            }
        }

        $this->warn_('Publishes', count($drifted).' published file(s) have local edits', $hint, $drifted);
    }

    /**
     * The status colors badges, alerts and action colors use: without them
     * Tailwind generates no `*-success` / `*-warning` / `*-info` utility,
     * and those surfaces render colorless — nothing errors.
     */
    protected function checkTheme(): void
    {
        $path = resource_path('css/app.css');

        if (! File::exists($path)) {
            return;
        }

        $css = (string) File::get($path);

        if (str_contains($css, '--color-success') || str_contains($css, 'kinetix.css')) {
            $this->ok('Theme', 'status tokens (success · warning · info) mapped');

            return;
        }

        $this->warn_('Theme', 'resources/css/app.css maps no status tokens (success · warning · info)',
            'Badges, alerts, stat chips and action colors render without their color. Copy them into app.css from '
            .'the docs (Installation → Theming → Status tokens); a new install gets them from kinetix:install.');
    }

    /**
     * The published agent skills kept out of Tailwind's class scan. Silent
     * when it doesn't apply: switched off, no Kinetix skills, no app.css, or
     * a Tailwind without `@source not` (older than 4.1).
     */
    protected function checkTailwindSources(): void
    {
        if (TailwindSourceExclusions::skipReason() !== null) {
            return;
        }

        $misplaced = TailwindSourceExclusions::misplaced();

        if ($misplaced !== []) {
            $this->warn_('Tailwind', 'resources/css/app.css has an @import/@source inside a block',
                'Tailwind only accepts @import and @source at the top level of the stylesheet, so the build fails. '
                .'Move these lines out of the block, after the stylesheet\'s @import lines.',
                array_map(static fn (int $line): string => "line {$line}", $misplaced));

            return;
        }

        $missing = TailwindSourceExclusions::missing();

        if ($missing === []) {
            $this->ok('Tailwind', 'agent skills excluded from the class scan');

            return;
        }

        $problem = TailwindSourceExclusions::placementProblem();

        $this->warn_('Tailwind', 'resources/css/app.css lets Tailwind scan the agent skills',
            'Class names quoted in the skills\' examples become CSS the app ships and never uses. '
            .($problem === null ? 'Run kinetix:upgrade, or add' : "kinetix:upgrade can't add them ({$problem}); add")
            .' these lines at the top level, after the stylesheet\'s @import lines.',
            array_map(static fn (string $path): string => "@source not '{$path}';", $missing));
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
