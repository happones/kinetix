<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Kinetix Panel Identity
    |--------------------------------------------------------------------------
    |
    | These values define the global branding of your Kinetix-powered panel.
    | They can be overridden per-panel once panel support is added.
    |
    */
    'brand' => [
        'name'    => env('APP_NAME', 'Kinetix'),
        'logo'    => env('KINETIX_BRAND_LOGO', null),
        'favicon' => env('KINETIX_BRAND_FAVICON', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Kinetix Tenancy
    |--------------------------------------------------------------------------
    |
    | When your application serves multiple teams on subdomains (e.g.
    | acme.example.com), set the column name used to match a tenant from
    | the request host. Kinetix then resolves the current team from the
    | subdomain and registers plain routes (no {team} prefix).
    | Set to null (default) to use standard route-parameter tenancy.
    |
    */
    'tenancy' => [
        'subdomain' => env('KINETIX_TENANCY_SUBDOMAIN', null),
    ],

    /*
    |--------------------------------------------------------------------------
    | Kinetix Assets
    |--------------------------------------------------------------------------
    |
    | Configure where Kinetix publishes its compiled frontend assets.
    | These are served from the public directory.
    |
    */
    'assets' => [
        'path'  => env('KINETIX_ASSETS_PATH', 'vendor/kinetix'),
        'cache' => env('KINETIX_ASSETS_CACHE', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Kinetix Filesystem
    |--------------------------------------------------------------------------
    |
    | The default filesystem disk used for file uploads (FileUpload field) and
    | for resolving asset URLs (ImageColumn). Defaults to "public"; point it at
    | any configured disk (e.g. "s3"). Per-component overrides are available via
    | FileUpload::disk() and ImageColumn::disk().
    |
    */
    'filesystem' => [
        'disk' => env('KINETIX_FILESYSTEM_DISK', 'public'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Kinetix Date & Time Formats
    |--------------------------------------------------------------------------
    |
    | Default output formats for date/datetime table columns and infolist
    | entries when no explicit format is given (`->date()`, `->dateTime()`).
    | These are Carbon *isoFormat* tokens, rendered in the application locale
    | (e.g. `ll` → "Jul 9, 2026" in en, "9 jul 2026" in es). Common tokens:
    | `L` 07/09/2026 · `LL` July 9, 2026 · `ll` Jul 9, 2026 · `lll` adds time.
    | Per-column overrides: `->date('d/m/Y')` (plain PHP format),
    | `->isoDate('LL')` (isoFormat), `->locale('fr')`.
    |
    */
    'formats' => [
        'date'     => env('KINETIX_DATE_FORMAT', 'll'),
        'datetime' => env('KINETIX_DATETIME_FORMAT', 'lll'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Kinetix Notifications
    |--------------------------------------------------------------------------
    |
    | Control how Kinetix handles notification delivery and persistence.
    |
    | - `database`: Set to true to enable database persistence via Laravel's
    |   database channel. When false, notifications are session-based only.
    |
    | - `sound`: Configure audio alerts for incoming notifications.
    |   Sound is only played for real-time (broadcast) or newly received
    |   notifications — never on the initial page load.
    |
    | - `limit`: Maximum number of unread notifications to load from the
    |   database on each page request. Only applies when database is enabled.
    |
    */
    'notifications' => [
        'database' => env('KINETIX_DATABASE_NOTIFICATIONS', false),

        // Broadcast system notifications (export/import done, etc.) in real time
        // via Laravel's broadcast channel — independent of how you configured
        // Laravel Echo. Enable this if you set up Echo/Reverb yourself instead
        // of filling the `broadcasting.echo` block below.
        'broadcast' => env('KINETIX_NOTIFICATIONS_BROADCAST', false),

        'limit' => env('KINETIX_NOTIFICATIONS_LIMIT', 15),

        'sound' => [
            'enabled' => env('KINETIX_NOTIFICATIONS_SOUND', true),
            'path'    => env('KINETIX_NOTIFICATIONS_SOUND_PATH', '/vendor/kinetix/notification.wav'),
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Broadcasting (Real-Time Notifications)
    |--------------------------------------------------------------------------
    |
    | Kinetix integrates with Laravel Echo for real-time push notifications
    | via WebSockets. Configure the Echo connection details below.
    |
    | Uncomment and configure the `echo` block to enable real-time support.
    | The broadcaster should match your Laravel `broadcasting.default` driver.
    |
    | Supported broadcasters: 'reverb', 'pusher', 'ably', 'null'
    |
    | Example with Laravel Reverb:
    |
    | 'echo' => [
    |     'broadcaster' => 'reverb',
    |     'key' => env('VITE_REVERB_APP_KEY'),
    |     'wsHost' => env('VITE_REVERB_HOST'),
    |     'wsPort' => env('VITE_REVERB_PORT', 8080),
    |     'wssPort' => env('VITE_REVERB_PORT', 443),
    |     'forceTLS' => env('VITE_REVERB_SCHEME', 'https') === 'https',
    |     'enabledTransports' => ['ws', 'wss'],
    | ],
    |
    */
    'broadcasting' => [

        // 'echo' => [
        //     'broadcaster' => 'reverb',
        //     'key' => env('VITE_REVERB_APP_KEY'),
        //     'wsHost' => env('VITE_REVERB_HOST', '127.0.0.1'),
        //     'wsPort' => env('VITE_REVERB_PORT', 8080),
        //     'wssPort' => env('VITE_REVERB_PORT', 443),
        //     'forceTLS' => env('VITE_REVERB_SCHEME', 'https') === 'https',
        //     'enabledTransports' => ['ws', 'wss'],
        // ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Teams (Multi-Tenancy)
    |--------------------------------------------------------------------------
    |
    | Set this to true if your application uses team-scoped or tenant-scoped
    | routing (e.g., Jetstream Teams). This will scope Kinetix's internal
    | routes and automatically configure URL default parameters for actions.
    |
    */
    'teams' => env('KINETIX_TEAMS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Team Switcher (optional)
    |--------------------------------------------------------------------------
    |
    | A header dropdown to switch the active team. Kinetix does NOT own your
    | Team model — it resolves the user's teams by convention and shares them
    | (with a ready-made switch URL each) via the `kinetix_teams` Inertia prop.
    | The component just visits that URL, so it works with whatever switch route
    | your app already has (e.g. the starter kit's `teams.switch`). Provide the
    | relation/attribute names and route names that match your app.
    |
    */
    'team_switcher' => [
        'enabled' => env('KINETIX_TEAM_SWITCHER_ENABLED', false),

        // Relation on the user model returning their teams, and the one (or
        // attribute) returning the active team.
        'teams_relation'   => 'teams',
        'current_relation' => 'currentTeam',

        // Attribute used as the team's display label.
        'name_attribute' => 'name',

        // Route name to switch teams (receives the team's route key). The team's
        // route key is `getRouteKey()` (slug when the model defines one).
        'switch_route' => env('KINETIX_TEAM_SWITCH_ROUTE', 'teams.switch'),

        // Optional route name to create a team — shown as "New team" when set.
        'create_route' => env('KINETIX_TEAM_CREATE_ROUTE'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Route Configuration
    |--------------------------------------------------------------------------
    |
    | The prefix used for Kinetix's internal API routes.
    | Change this if it conflicts with your application's existing routes.
    |
    */
    'route_prefix' => env('KINETIX_ROUTE_PREFIX', '_kinetix'),

    /*
    |--------------------------------------------------------------------------
    | Middleware
    |--------------------------------------------------------------------------
    |
    | The middleware stack applied to all Kinetix internal routes.
    | You can add your own middleware here (e.g., role-based access control).
    |
    */
    'middleware' => ['web', 'auth'],

    /*
    |--------------------------------------------------------------------------
    | Permissions (optional — requires spatie/laravel-permission)
    |--------------------------------------------------------------------------
    |
    | Kinetix layers a feature-scoped roles & permissions system on top of
    | spatie/laravel-permission. Enforcement still flows through Laravel's Gate
    | (Kinetix actions already use it), so this only adds authoring + UI.
    |
    | - `teams`: scope roles/permissions per team. When true, Kinetix wires
    |   spatie's team id to the starter-kit `currentTeam` via middleware.
    | - `super_admin_role`: a role that bypasses every gate check (Gate::before).
    | - `guard`: the auth guard permissions are registered under.
    |
    */
    'permissions' => [
        'enabled'          => env('KINETIX_PERMISSIONS_ENABLED', false),
        'teams'            => env('KINETIX_PERMISSIONS_TEAMS', false),
        'super_admin_role' => env('KINETIX_SUPER_ADMIN_ROLE', 'super-admin'),
        'guard'            => env('KINETIX_PERMISSIONS_GUARD', 'web'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Membership & Provisioning (optional)
    |--------------------------------------------------------------------------
    |
    | An admin-provisioned onboarding model — an alternative to the starter-kit's
    | self-serve team invitations. An admin adds an email + role; the person
    | activates by setting a password. No personal team is created and the role
    | is a dynamic Kinetix role, so members never become owners or admins.
    |
    | - `assignable_roles`: the only roles a provisioner may assign. This is the
    |   guard that keeps "added members" from ever becoming admin.
    | - `user_model`: the host's User model, created on activation.
    | - `attach_member` / `detach_member`: optional callables to (de)attach the
    |   user to the host's own team pivot — Kinetix never touches it directly.
    |   Signature: fn ($user, MemberProvision $provision) => void.
    | - `activation_view`: Inertia page rendered for the set-password screen.
    |
    */
    'membership' => [
        'enabled'           => env('KINETIX_MEMBERSHIP_ENABLED', false),
        'teams'             => env('KINETIX_MEMBERSHIP_TEAMS', false),
        'user_model'        => env('KINETIX_MEMBERSHIP_USER_MODEL', 'App\\Models\\User'),
        'assignable_roles'  => ['editor', 'viewer'],
        'activation_expiry' => env('KINETIX_MEMBERSHIP_ACTIVATION_HOURS', 72),
        'activation_view'   => env('KINETIX_MEMBERSHIP_ACTIVATION_VIEW', 'Kinetix/MemberActivation'),
        'redirect_after'    => env('KINETIX_MEMBERSHIP_REDIRECT', '/'),
        'attach_member'     => null,
        'detach_member'     => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Settings (optional)
    |--------------------------------------------------------------------------
    |
    | A database-backed, class-based settings panel. A SettingsPage defines its
    | fields with the Kinetix Forms engine; values are persisted in the
    | `kinetix_settings` table and read via the KinetixSettings facade.
    |
    | - `teams`: scope settings per team (null team = global).
    | - `cache`: cache each scope's values (invalidated on write).
    | - `pages`: registered SettingsPage classes (or call KinetixSettings::pages()).
    | - `view`: the Inertia page the bundled controller renders.
    |
    */
    'settings' => [
        'enabled'   => env('KINETIX_SETTINGS_ENABLED', false),
        'teams'     => env('KINETIX_SETTINGS_TEAMS', false),
        'cache'     => env('KINETIX_SETTINGS_CACHE', true),
        'cache_key' => 'kinetix.settings',
        'view'      => env('KINETIX_SETTINGS_VIEW', 'Kinetix/Settings'),

        // @var array<int, class-string<\Happones\Kinetix\Settings\SettingsPage>>
        'pages' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Webhooks (optional)
    |--------------------------------------------------------------------------
    |
    | Let customers subscribe their own endpoints to platform events. Declare the
    | subscribable events with KinetixWebhooks::events(); fire them with
    | KinetixWebhooks::fire(). Deliveries are signed (HMAC), queued, retried and
    | logged, and customer URLs are validated against SSRF.
    |
    | - `allow_private`: permit private/loopback URLs (dev/testing only — leave
    |   false in production). Needed to point at a local catcher.
    |
    */
    'webhooks' => [
        'enabled' => env('KINETIX_WEBHOOKS_ENABLED', false),

        // Delivery driver: 'auto' uses spatie/laravel-webhook-server when installed
        // (its tuned retries/backoff), otherwise the native queued job. Force with
        // 'spatie' / 'native'. Note: the spatie driver signs with spatie's header
        // (config webhook-server.signature_header_name, default 'Signature') and
        // uses spatie's tries/timeout config.
        'driver' => env('KINETIX_WEBHOOKS_DRIVER', 'auto'),

        'teams'          => env('KINETIX_WEBHOOKS_TEAMS', false),
        'allow_private'  => env('KINETIX_WEBHOOKS_ALLOW_PRIVATE', false),
        'timeout'        => env('KINETIX_WEBHOOKS_TIMEOUT', 10),
        'tries'          => env('KINETIX_WEBHOOKS_TRIES', 3),
        'retention_days' => env('KINETIX_WEBHOOKS_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | Spotlight Command Palette (optional)
    |--------------------------------------------------------------------------
    |
    | A global Cmd+K search over models, navigation and actions. Register sources
    | with the KinetixSpotlight facade. Model searches use laravel/scout when the
    | model is Searchable, otherwise a capped LIKE query. Results are
    | authorization-aware — sources self-gate and per-record policies are honored.
    |
    | - `driver`: 'auto' uses Scout for Searchable models, else 'database' (LIKE).
    | - `limit`: max results per source.
    |
    */
    'spotlight' => [
        'enabled' => env('KINETIX_SPOTLIGHT_ENABLED', false),
        'driver'  => env('KINETIX_SPOTLIGHT_DRIVER', 'auto'),
        'limit'   => env('KINETIX_SPOTLIGHT_LIMIT', 5),
    ],

    /*
    |--------------------------------------------------------------------------
    | Developer Tokens (optional — requires laravel/sanctum)
    |--------------------------------------------------------------------------
    |
    | Self-service personal access tokens. The User model must use
    | Laravel\Sanctum\HasApiTokens. Declare the abilities (scopes) a token may be
    | granted here, or via KinetixTokens::scopes([...]). Empty = full-access ('*').
    |
    */
    'tokens' => [
        'enabled' => env('KINETIX_TOKENS_ENABLED', false),

        // @var array<string, string> ability key => human label
        'scopes' => [],
    ],

    /*
    |--------------------------------------------------------------------------
    | Accessibility (optional)
    |--------------------------------------------------------------------------
    |
    | Per-user accessibility preferences (reduced motion, high contrast, text
    | size, underlined links, enhanced focus). Persisted server-side and applied
    | to the document root. Pair with <KinetixAccessibilityPanel> + the
    | KinetixAccessibility Vue plugin.
    |
    */
    'accessibility' => [
        'enabled' => env('KINETIX_ACCESSIBILITY_ENABLED', false),

        // Defaults applied when a user has not customized a preference.
        'defaults' => [
            'reducedMotion'  => false,
            'highContrast'   => false,
            'textSize'       => 'normal', // normal | large | x-large
            'underlineLinks' => false,
            'enhancedFocus'  => false,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | GDPR self-service (optional)
    |--------------------------------------------------------------------------
    |
    | Lets users export their personal data and delete their account. Register
    | the data sections with KinetixGdpr::export('profile', fn ($user) => ...).
    | Deletion either 'anonymize's the configured PII columns or hard-'delete's
    | the record; override entirely with KinetixGdpr::deleteUsing(...).
    |
    */
    'gdpr' => [
        'enabled' => env('KINETIX_GDPR_ENABLED', false),

        // 'anonymize' scrubs the columns below; 'delete' removes the record.
        'deletion' => env('KINETIX_GDPR_DELETION', 'anonymize'),

        // Require the user's current password before exporting/deleting.
        'require_password' => env('KINETIX_GDPR_REQUIRE_PASSWORD', true),

        // @var array<string, mixed> column => replacement value (or closure) on anonymize
        'anonymize' => [
            // 'name'  => 'Deleted user',
            // 'email' => null,
        ],

        // Where the SPA navigates after the account is deleted.
        'redirect' => env('KINETIX_GDPR_REDIRECT', '/'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Onboarding (optional)
    |--------------------------------------------------------------------------
    |
    | A first-run setup checklist. Declare steps with KinetixOnboarding::step(...)
    | in a service provider; per-user (or per-team) completion is persisted. Each
    | step can auto-complete via a `completedUsing` callback, or be marked done
    | manually from the checklist UI.
    |
    */
    'onboarding' => [
        'enabled' => env('KINETIX_ONBOARDING_ENABLED', false),

        // Track progress per team instead of per user.
        'teams' => env('KINETIX_ONBOARDING_TEAMS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Connected Accounts / Social auth (optional)
    |--------------------------------------------------------------------------
    |
    | Link OAuth providers to a user (settings management) and, optionally,
    | sign in / register with a provider. Requires laravel/socialite and the
    | provider credentials in config/services.php. Declare providers below or
    | via KinetixConnectedAccounts::providers([...]).
    |
    */
    'connected_accounts' => [
        'enabled' => env('KINETIX_CONNECTED_ACCOUNTS_ENABLED', false),

        // Opt-in guest login/registration via a provider (find-or-create + login).
        'login_enabled' => env('KINETIX_CONNECTED_ACCOUNTS_LOGIN', false),

        // Block unlinking the last provider when the user has no password set
        // (prevents account lockout).
        'prevent_lockout' => true,

        // Where to return after linking, and after a successful / failed login.
        'redirect'               => env('KINETIX_CONNECTED_ACCOUNTS_REDIRECT', '/'),
        'login_redirect'         => env('KINETIX_CONNECTED_ACCOUNTS_LOGIN_REDIRECT', '/'),
        'login_failure_redirect' => env('KINETIX_CONNECTED_ACCOUNTS_LOGIN_FAILURE', '/login'),

        // Providers offered for linking / login. key => [label, icon, color].
        // 'icon' maps to a built-in brand glyph (github|google) the Vue
        // component knows, or any name you handle yourself.
        'providers' => [
            // 'github' => ['label' => 'GitHub', 'icon' => 'github', 'color' => '#181717'],
            // 'google' => ['label' => 'Google', 'icon' => 'google', 'color' => '#4285F4'],
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Comments (optional)
    |--------------------------------------------------------------------------
    |
    | Polymorphic, threaded comments on any model. Declare which models accept
    | comments with KinetixComments::for([Post::class, Task::class]). Each user
    | edits/deletes only their own; a host "view" policy on the model is honored.
    |
    */
    'comments' => [
        'enabled' => env('KINETIX_COMMENTS_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Tags (optional)
    |--------------------------------------------------------------------------
    |
    | Polymorphic tags on any model. Add the HasKinetixTags trait to taggable
    | models and allowlist them with KinetixTags::for([Post::class, ...]).
    | Tags are team-scoped automatically when kinetix.teams is on.
    |
    */
    'tags' => [
        'enabled' => env('KINETIX_TAGS_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Announcements / "What's new" (optional)
    |--------------------------------------------------------------------------
    |
    | A product announcements feed with a per-user unread badge. Publish entries
    | with KinetixAnnouncements::publish(...) (seeder, deploy step, anywhere) and
    | mount the <KinetixAnnouncements> trigger in your header.
    |
    */
    'announcements' => [
        'enabled' => env('KINETIX_ANNOUNCEMENTS_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Notification Preferences (optional)
    |--------------------------------------------------------------------------
    |
    | A per-user opt-in/out matrix of notification types × channels. Declare the
    | types with KinetixNotificationPreferences::types([...]) (or below) and gate
    | sends with KinetixNotificationPreferences::channelsFor($user, $type, $chans)
    | inside a Notification's via(). Defaults to enabled; only opt-outs are stored.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Saved Views (optional)
    |--------------------------------------------------------------------------
    |
    | Per-user table presets (search + filters + sort + visible columns). Enable
    | it here, then call ->saveViews() on a Table to surface the views dropdown.
    |
    */
    'saved_views' => [
        'enabled' => env('KINETIX_SAVED_VIEWS_ENABLED', false),
    ],

    'notification_preferences' => [
        'enabled' => env('KINETIX_NOTIFICATION_PREFERENCES_ENABLED', false),

        // Delivery channels offered in the matrix (key => label).
        'channels' => [
            'mail'      => 'Email',
            'database'  => 'In-app',
            'broadcast' => 'Push',
        ],

        // Notification types (key => label). Or register them at runtime.
        'types' => [
            // 'orders'    => 'Order updates',
            // 'marketing' => 'Marketing & tips',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Forms
    |--------------------------------------------------------------------------
    |
    | Defaults for form fields. `rich_editor` picks the default driver for the
    | RichEditor field (override per field with ->editor()):
    |   - 'basic'    : zero-dependency contenteditable + toolbar (HTML output)
    |   - 'tiptap'   : richer WYSIWYG — requires @tiptap/core + @tiptap/starter-kit
    |   - 'markdown' : zero-dependency textarea + live preview (Markdown output)
    |
    */
    'forms' => [
        'rich_editor' => env('KINETIX_RICH_EDITOR', 'basic'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Locale / Language switcher (optional)
    |--------------------------------------------------------------------------
    |
    | A self-service language switcher. List the locales you support (code =>
    | native label) and drop <KinetixLanguageSwitcher /> in your header. The
    | choice is persisted in the session and, when the `kinetix.locale`
    | middleware is on the web group, applied with App::setLocale() on every
    | request. Add the published migration to also persist it on the user's
    | `locale` column (survives across devices/sessions).
    |
    */
    'locale' => [
        'enabled' => env('KINETIX_LOCALE_ENABLED', false),

        // Supported locales: code => native label (shown in its own language).
        'locales' => [
            'en' => 'English',
            'es' => 'Español',
            'fr' => 'Français',
            'pt' => 'Português',
        ],

        // Persist the choice on the authenticated user's `locale` column when it
        // exists (publish kinetix-locale-migrations). Falls back to the session.
        'store_on_user' => env('KINETIX_LOCALE_STORE_ON_USER', true),

        // Session key used to remember the selected locale.
        'session_key' => 'kinetix.locale',
    ],

    /*
    |--------------------------------------------------------------------------
    | Presence / Online indicators (optional)
    |--------------------------------------------------------------------------
    |
    | Show who's online in real time over a Reverb/Pusher presence channel.
    | Kinetix registers the channel authorization and shares the (team-resolved)
    | channel name; drop <KinetixOnlineUsers /> for a live avatar facepile, or
    | use useKinetixPresence() for a green online dot anywhere. Requires
    | broadcasting configured (php artisan kinetix:install --broadcasting).
    |
    */
    'presence' => [
        'enabled' => env('KINETIX_PRESENCE_ENABLED', false),

        // Presence channel base name. Suffixed with the team id when
        // `kinetix.teams` is on, so each team gets its own presence room.
        'channel' => env('KINETIX_PRESENCE_CHANNEL', 'kinetix-presence'),

        // User attributes exposed to other members on the channel.
        'name_attribute'   => 'name',
        'avatar_attribute' => 'avatar_url',
    ],

    /*
    |--------------------------------------------------------------------------
    | Browser Sessions / Device management (optional)
    |--------------------------------------------------------------------------
    |
    | Let users see their active browser sessions (device, browser, IP, last
    | active) and log out every other device. The session list requires
    | SESSION_DRIVER=database (and the `sessions` table). No migration is
    | published — it reads Laravel's own sessions table.
    |
    */
    'sessions' => [
        'enabled' => env('KINETIX_SESSIONS_ENABLED', false),

        // Require the current password to confirm logging out other devices
        // (skipped automatically for users who have no password set).
        'require_password' => env('KINETIX_SESSIONS_REQUIRE_PASSWORD', true),
    ],

    /*
    |--------------------------------------------------------------------------
    | Wizards (optional)
    |--------------------------------------------------------------------------
    |
    | Multi-step flows. The standalone <KinetixWizard> can gate access to parts
    | of your app: map a wizard slug to the route a user is redirected to until
    | they complete it, then apply the `kinetix.wizard:<slug>` middleware to the
    | routes you want gated. Completion is persisted per user (or per team).
    |
    */
    'wizards' => [
        'enabled' => env('KINETIX_WIZARDS_ENABLED', false),

        // Track completion per team instead of per user.
        'teams' => env('KINETIX_WIZARDS_TEAMS', false),

        // @var array<string, string> wizard slug => route name to redirect to
        'gates' => [
            // 'account-setup' => 'account.setup',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Feature Flags (optional)
    |--------------------------------------------------------------------------
    |
    | Gradual rollout and plan-gating. Define flags with the KinetixFeatures
    | facade; resolve them through laravel/pennant when installed, otherwise a
    | native closure evaluator. Flags compose with Billing — a resolver can defer
    | to `$user->canUseFeature(...)`.
    |
    | - `driver`: 'auto' uses pennant when installed, else native. 'pennant' /
    |   'native' force it.
    | - `teams`: resolve flags for the active team instead of the user.
    |
    */
    'features' => [
        'enabled' => env('KINETIX_FEATURES_ENABLED', false),
        'driver'  => env('KINETIX_FEATURES_DRIVER', 'auto'),
        'teams'   => env('KINETIX_FEATURES_TEAMS', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Impersonation (optional)
    |--------------------------------------------------------------------------
    |
    | Lets an admin "log in as" another user. The `users.impersonate` ability
    | controls who may impersonate; the built-in escalation guard blocks
    | impersonating a super-admin (unless you are one). Apply the
    | `kinetix.impersonation.protect` middleware to sensitive routes to block
    | them while impersonating.
    |
    */
    'impersonation' => [
        'enabled'       => env('KINETIX_IMPERSONATION_ENABLED', false),
        'redirect_to'   => env('KINETIX_IMPERSONATION_REDIRECT', '/'),
        'redirect_back' => env('KINETIX_IMPERSONATION_REDIRECT_BACK', '/'),

        // Optional override: fn (Authenticatable $impersonator, Authenticatable $target): bool
        'can_impersonate' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Activity Log (optional)
    |--------------------------------------------------------------------------
    |
    | A native, team-scoped audit trail + event spine. Add the
    | `LogsKinetixActivity` trait to a model to auto-record create/update/delete
    | (with an old→new diff), or log anything via the KinetixActivity facade.
    | Read it back with the <KinetixActivityLog> component — globally or scoped
    | to one record (per feature).
    |
    | - `teams`: scope entries per team (null team = global).
    | - `per_page`: page size for the paginated feed.
    | - `retention_days`: window kept by `kinetix:activity:prune`.
    |
    */
    'activity' => [
        'enabled' => env('KINETIX_ACTIVITY_ENABLED', false),

        // Storage driver: 'auto' uses spatie/laravel-activitylog when installed,
        // otherwise the native kinetix_activity table. Force with 'spatie' / 'native'.
        'driver' => env('KINETIX_ACTIVITY_DRIVER', 'auto'),

        'teams'          => env('KINETIX_ACTIVITY_TEAMS', false),
        'per_page'       => env('KINETIX_ACTIVITY_PER_PAGE', 15),
        'retention_days' => env('KINETIX_ACTIVITY_RETENTION_DAYS', 365),
    ],

    /*
    |--------------------------------------------------------------------------
    | Billing (optional — requires laravel/cashier + @stripe/stripe-js)
    |--------------------------------------------------------------------------
    |
    | The Kinetix Billing module wraps Laravel Cashier (Stripe) to provide
    | plans, pricing tables, subscriptions, invoices and payment methods.
    | It only activates when `enabled` is true AND laravel/cashier is installed.
    | `billable` is the Cashier Billable model (User, Team, Organization, …).
    |
    */
    'billing' => [
        'enabled'       => env('KINETIX_BILLING_ENABLED', false),
        'teams'         => env('KINETIX_BILLING_TEAMS', false),
        'trial_generic' => env('KINETIX_BILLING_TRIAL_GENERIC', false),
        'billable'      => env('KINETIX_BILLING_BILLABLE', 'App\\Models\\User'),
        'plan_model'    => env('KINETIX_BILLING_PLAN_MODEL', 'Happones\\Kinetix\\Billing\\Plan'),

        // Cashier subscription "type" (Cashier's default is 'default').
        'subscription' => env('KINETIX_BILLING_SUBSCRIPTION', 'default'),

        // Currency symbol used when formatting prices in the UI.
        'currency'        => env('KINETIX_BILLING_CURRENCY', 'USD'),
        'currency_symbol' => env('KINETIX_BILLING_CURRENCY_SYMBOL', '$'),

        // Product label shown on downloaded invoices.
        'product' => env('KINETIX_BILLING_PRODUCT', 'Subscription'),

        // When true, invoice download links point directly to Stripe's hosted
        // PDF instead of going through the Kinetix download route (DomPDF).
        'invoices_use_stripe_url' => env('KINETIX_BILLING_INVOICES_USE_STRIPE_URL', false),

        // Inertia page component the bundled BillingController renders.
        'view' => env('KINETIX_BILLING_VIEW', 'Billing/Index'),

        // Optionally resolve a different billable from the authenticated user
        // (e.g. fn ($user) => $user->currentTeam). Null = the user itself.
        'resolve_billable' => null,

        // Route registration for the bundled BillingController.
        'auto_routes'  => env('KINETIX_BILLING_AUTO_ROUTES', false),
        'route_prefix' => env('KINETIX_BILLING_ROUTE_PREFIX', 'billing'),
        'route_name'   => env('KINETIX_BILLING_ROUTE_NAME', 'billing.'),
        'middleware'   => ['web', 'auth'],
    ],

    /*
    |--------------------------------------------------------------------------
    | Queue metrics / Horizon widget (optional)
    |--------------------------------------------------------------------------
    |
    | A lightweight, embeddable queue-health widget. It does NOT replace the
    | Horizon dashboard — it surfaces a few live metrics (throughput, recent &
    | failed jobs, pending per queue) inside your own Kinetix dashboard. When
    | Laravel Horizon is installed it reads Horizon's metrics; otherwise it falls
    | back to queue sizes + the failed_jobs table. Access is gated by the
    | `viewKinetixQueue` ability (defaults to allow in `local` only).
    |
    */
    'queue' => [
        'enabled' => env('KINETIX_QUEUE_ENABLED', false),

        // Queues to monitor when Horizon isn't installed. `connection: null`
        // uses the default queue connection.
        'queues' => [
            ['connection' => null, 'queue' => 'default'],
        ],

        // Frontend poll interval in milliseconds.
        'poll' => env('KINETIX_QUEUE_POLL', 5000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Health / Status widget (optional)
    |--------------------------------------------------------------------------
    |
    | A lightweight, embeddable application-health widget powered by
    | spatie/laravel-health. It surfaces the latest stored check results (status
    | per check + an overall badge) inside your own Kinetix dashboard. Requires
    | spatie/laravel-health installed and its checks scheduled. Access is gated by
    | the `viewKinetixHealth` ability (defaults to allow in `local` only).
    |
    */
    'health' => [
        'enabled' => env('KINETIX_HEALTH_ENABLED', false),

        // Frontend poll interval in milliseconds.
        'poll' => env('KINETIX_HEALTH_POLL', 30000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Scheduled Reports (optional)
    |--------------------------------------------------------------------------
    |
    | Email an Exporter's output on a schedule. Register reports in a service
    | provider with KinetixReports::register(ScheduledReport::make(...)), then
    | run `kinetix:reports:send` from your scheduler (filter with
    | `--frequency=daily|weekly|monthly`). Each due report builds its export file
    | and mails it to the recipients as an attachment.
    |
    */
    'reports' => [
        'enabled' => env('KINETIX_REPORTS_ENABLED', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Mail Templates (optional)
    |--------------------------------------------------------------------------
    |
    | Editable email templates (subject + Markdown/HTML body with `{{ var }}`
    | placeholders), managed from the <KinetixMailTemplates> UI and stored in the
    | kinetix_mail_templates table. Your app supplies the variable data and
    | triggers sends via KinetixMail::send($to, $key, $data). The manager + test
    | endpoints are gated by the `viewKinetixMail` ability (default allow-local).
    |
    */
    'mail_templates' => [
        'enabled' => env('KINETIX_MAIL_TEMPLATES_ENABLED', false),
    ],

];
