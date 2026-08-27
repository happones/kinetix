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
    | Two disks, deliberately separate. `disk` holds user-facing files that are
    | meant to be linked to — FileUpload fields, ImageColumn/ImageEntry asset
    | URLs — and defaults to "public". `private_disk` holds generated artifacts
    | nobody should reach by URL, and defaults to "local". Point either at any
    | configured disk (e.g. "s3"). Per-component overrides are available via
    | FileUpload::disk() and ImageColumn::disk().
    |
    */
    'filesystem' => [
        'disk' => env('KINETIX_FILESYSTEM_DISK', 'public'),

        // Disk for GENERATED artifacts: exports, uploaded import files, report
        // runs and GDPR personal-data dumps. Keep this private — on a public
        // disk these are served at a guessable /storage/... URL with no auth,
        // so the token-guarded download endpoints become a side door and a
        // user's personal-data export is protected by nothing but obscurity.
        'private_disk' => env('KINETIX_FILESYSTEM_PRIVATE_DISK', 'local'),

        // Fallback size ceiling (kilobytes) for a FileUpload field that doesn't
        // declare maxSize(), so an unbounded upload can't fill the disk.
        'upload_max_size' => env('KINETIX_UPLOAD_MAX_SIZE', 12288),

        // Store uploads under a per-user subdirectory. This is what stops one
        // user from naming — and therefore deleting — another user's file, since
        // uploads otherwise share one flat directory with no ownership record.
        'scope_uploads_by_user' => env('KINETIX_SCOPE_UPLOADS_BY_USER', true),

        // Extensions refused by a FileUpload field that declares no accept():
        // anything a browser would execute if served from the app's own origin.
        // A field that explicitly accepts one of these overrides the list.
        'upload_blocked_extensions' => [
            'html', 'htm', 'xhtml', 'shtml', 'svg', 'xml', 'xsl',
            'js', 'mjs', 'cjs', 'jsx', 'vue',
            'php', 'php3', 'php4', 'php5', 'php7', 'php8', 'phtml', 'phar', 'phps',
            'swf', 'jar', 'hta', 'htaccess',
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Exports
    |--------------------------------------------------------------------------
    |
    | How long (minutes) an export / GDPR download link stays valid. The link's
    | token is bound to the user it was minted for, so this only bounds how long
    | that user's own link keeps working — it is not the only thing protecting
    | the file. Null disables expiry (not recommended: these links travel through
    | email and proxy logs).
    |
    */
    'exports' => [
        'download_ttl' => env('KINETIX_EXPORT_DOWNLOAD_TTL', 1440),
    ],

    /*
    |--------------------------------------------------------------------------
    | Imports
    |--------------------------------------------------------------------------
    |
    | Defaults for the import dialog and for how much of an uploaded file it
    | reads. Previewing NEVER loads the whole file: the reader streams the
    | first `preview_rows` data rows and stops, so a ten-row preview costs the
    | same on a thousand-row file as on a million-row one. Any importer can
    | override these per class ($preview, $previewRows, $previewColumns,
    | $layout, $maxUploadSize).
    |
    */
    'imports' => [
        // Upload ceiling in kilobytes (100 MB by default — a million-row CSV
        // is roughly that). PHP's own `upload_max_filesize` / `post_max_size`
        // still cap this, so raise those too before raising this.
        'max_upload_size' => env('KINETIX_IMPORT_MAX_UPLOAD_SIZE', 102400),

        // Whether the dialog shows the sample-data table at all.
        'preview' => env('KINETIX_IMPORT_PREVIEW', true),

        // Sample data rows parsed for the preview table. This is also the read
        // limit — the reader stops here instead of parsing the rest of the file.
        'preview_rows' => env('KINETIX_IMPORT_PREVIEW_ROWS', 10),

        // Source columns the preview table renders before the rest collapse
        // behind a "show all columns" toggle (0 = no cap). A wide file would
        // otherwise turn the preview into a horizontal scroll nobody reads.
        'preview_columns' => env('KINETIX_IMPORT_PREVIEW_COLUMNS', 8),

        // Dialog surface. 'auto' promotes the dialog to a full-screen modal
        // once the file has more than `fullscreen_threshold` source columns;
        // 'modal' | 'fullscreen' | 'sheet' pin one surface regardless.
        'layout'               => env('KINETIX_IMPORT_LAYOUT', 'auto'),
        'fullscreen_threshold' => env('KINETIX_IMPORT_FULLSCREEN_THRESHOLD', 12),

        // Rows per read pass for spreadsheets (xls/xlsx). Unlike CSV, a
        // spreadsheet has no streaming API, so the reader re-opens the file
        // per window of rows instead of materializing the whole sheet — this
        // is what bounds worker memory on a huge workbook.
        'spreadsheet_chunk_size' => env('KINETIX_IMPORT_SPREADSHEET_CHUNK', 2000),
    ],

    /*
    |--------------------------------------------------------------------------
    | Published Translations
    |--------------------------------------------------------------------------
    |
    | Which locales `vendor:publish --tag=kinetix-translations` copies into
    | lang/ (and `kinetix:upgrade` refreshes). Your app is English-only? Set
    | ['en'] and the other catalogs are never published. null/empty = all
    | shipped locales (en, es, fr, pt, zh, ja, ru). The env var takes a
    | comma-separated list: KINETIX_TRANSLATION_LOCALES=en,es
    |
    */
    'translations' => [
        'locales' => env('KINETIX_TRANSLATION_LOCALES'),

        // Options forwarded to `vue-i18n:generate` when `kinetix:upgrade`
        // recompiles the bundle after re-publishing translations. An app that
        // compiles per-locale files MUST mirror its flags here — otherwise
        // upgrades regenerate the single-file bundle it doesn't import and
        // leave the files it DOES import stale (raw kinetix.* keys in the UI).
        'vue_i18n_options' => [
            // '--multi-locales' => true,
        ],
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

        // Database-mode fallback poll interval in milliseconds (0 disables it).
        // Without Echo this is what makes new notifications appear (badge +
        // toast) without a page navigation; with Echo it's redundant but
        // harmless (partial reloads only).
        'poll' => env('KINETIX_NOTIFICATIONS_POLL', 30000),

        // Scope the bell per team: true/false wins, null inherits the global
        // `kinetix.teams` switch. When on, the list shows only notifications
        // stamped with the active team (Notification::team(), auto-stamped by
        // the import/export jobs) plus unstamped global ones.
        'teams' => env('KINETIX_NOTIFICATIONS_TEAMS'),

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
    | This is also the DEFAULT for every module's own `teams` flag (permissions,
    | membership, settings, webhooks, onboarding, wizards, features, activity,
    | billing): leave a module's flag at null to inherit this value, or set it
    | to true/false explicitly to override per module (e.g. a team-scoped app
    | with personal billing).
    |
    */
    'teams' => env('KINETIX_TEAMS_ENABLED', false),

    /*
    |--------------------------------------------------------------------------
    | Host-Model Key Types
    |--------------------------------------------------------------------------
    |
    | The column type Kinetix migrations use for ids that reference YOUR models
    | (user_id, team_id, morph ids). 'auto' inspects the model at migrate time
    | (HasUlids → ulid, HasUuids → uuid, string $keyType → string, else
    | bigint). Pin 'bigint' | 'uuid' | 'ulid' | 'string' explicitly when
    | detection can't see your setup. Morph targets can be any model, so
    | 'morph' has no auto mode and defaults to bigint.
    |
    */
    'key_types' => [
        'user'  => env('KINETIX_USER_KEY_TYPE', 'auto'),
        'team'  => env('KINETIX_TEAM_KEY_TYPE', 'auto'),
        'morph' => env('KINETIX_MORPH_KEY_TYPE', 'bigint'),
    ],

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
    | IMPORTANT: every Kinetix module mounts its endpoints under this prefix
    | (`{current_team}/{route_prefix}/…` when teams are on) and the published Vue
    | components call those URLs themselves. Never register your own controller
    | on a different path expecting a Kinetix component to hit it — run
    | `php artisan kinetix:routes` to see the URLs the frontend actually uses.
    |
    */
    'route_prefix' => env('KINETIX_ROUTE_PREFIX', '_kinetix'),

    /*
    |--------------------------------------------------------------------------
    | Agent Skills
    |--------------------------------------------------------------------------
    |
    | Where `vendor:publish --tag=kinetix-skills` writes the per-module skills
    | that coding agents load (they only read the project's own directory, never
    | vendor/). Change it if your agent tooling looks elsewhere, e.g.
    | `.agents/skills` or `.opencode/skills`.
    |
    */
    'skills_path' => env('KINETIX_SKILLS_PATH', '.claude/skills'),

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
    | - `owner_bypass`: grant a team's OWNER every REGISTERED ability (model
    |   policies still run, so the bypass can't cross the tenancy boundary).
    |   Ownership lives in your team schema, not in a role, so it needs its own
    |   switch: `true` uses the host's `$user->ownsTeam($team)`; a callback
    |   receives `($user, $team)`. Write callbacks as `[Class::class, 'method']`
    |   or an invokable class-string — a closure here breaks `config:cache`.
    | - `guard`: the auth guard permissions are registered under.
    |
    */
    'permissions' => [
        'enabled'          => env('KINETIX_PERMISSIONS_ENABLED', false),
        'teams'            => env('KINETIX_PERMISSIONS_TEAMS'), // null = inherit kinetix.teams
        'super_admin_role' => env('KINETIX_SUPER_ADMIN_ROLE', 'super-admin'),
        // true | [Class, 'method'] | invokable class-string | null (off).
        // (A closure works but breaks `config:cache` — prefer the callables.)
        'owner_bypass' => env('KINETIX_PERMISSIONS_OWNER_BYPASS'),
        'guard'        => env('KINETIX_PERMISSIONS_GUARD', 'web'),

        // How the `kinetix_permissions` prop discovers abilities the Gate
        // grants WITHOUT a stored row, so the SPA's can() map matches what the
        // server would authorize:
        //
        //   'auto'  (default) — the owner bypass answered once (it grants every
        //           registered ability or none, so one verdict settles the
        //           catalog), plus abilities the app defined with
        //           Gate::define(). Covers every dynamic grant Kinetix
        //           documents, at O(1).
        //   'sweep' — additionally ask the Gate about EVERY registered ability.
        //           Needed only when the app registers its OWN Gate::before
        //           over registry keys (which the permissions docs advise
        //           against). Costs one Gate call per registered ability on
        //           every full page load — ~40ms on a 280-key catalog.
        //   'off'   — stored rows only.
        'dynamic_grants' => env('KINETIX_PERMISSIONS_DYNAMIC_GRANTS', 'auto'),

        // Role names that the management UI/endpoints refuse to create, rename
        // to, edit or delete. `null` protects just the super-admin role above;
        // set an explicit array to protect more (e.g. ['super-admin', 'owner']).
        'protected_roles' => null,

        // Directory (+ namespace) auto-scanned for `Resource` subclasses whose
        // CRUD abilities are derived automatically — no per-resource
        // registration needed. Set `discover_path` to null to disable and
        // register resources manually via `KinetixPermissions::resource()`.
        'discover_path'      => app_path('Kinetix/Resources'),
        'discover_namespace' => 'App\\Kinetix\\Resources',
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
    |   guard that keeps "added members" from ever becoming admin. A list of role
    |   names, or a callback `($teamId) => array` for per-team catalogs.
    | - `user_model`: the host's User model, created on activation.
    | - `attach_member` / `detach_member`: callbacks to (de)attach the user to the
    |   host's own team pivot — Kinetix never touches it directly. Signature:
    |   `($user, MemberProvision $provision) => void`. REQUIRED once team scoping
    |   is on, or an activated member belongs to no team.
    | - `activation_view`: Inertia page rendered for the set-password screen.
    |
    | Write every callback above as `[Class::class, 'method']` or the class-string
    | of an invokable class. A closure in this file breaks `config:cache` — the
    | app then cannot be deployed with a cached config.
    |
    */
    /*
    |--------------------------------------------------------------------------
    | Credentials (optional)
    |--------------------------------------------------------------------------
    |
    | How people prove who they are: the password lifecycle today, and the
    | identity fields they sign in with (see docs/credentials.md — that half is
    | host-side wiring: a migration plus your Fortify config).
    |
    | Every knob below is OFF by default, so enabling the module changes nothing
    | until you opt into a rule. Publish the migrations with
    | `--tag=kinetix-credentials-migrations`.
    |
    */
    'credentials' => [
        'enabled' => env('KINETIX_CREDENTIALS_ENABLED', false),

        // The model a login resolves to. Null falls back to
        // `membership.user_model`, then App\Models\User.
        'user_model' => env('KINETIX_CREDENTIALS_USER_MODEL'),

        // What a person may sign in with. ['email'] is exactly today's
        // behavior; add 'username' / 'phone' for staff who have no email
        // address. Publish the columns with `--tag=kinetix-identity-migrations`
        // and point Fortify at KinetixIdentity::attempt() — docs/credentials.md.
        'identity' => [
            'fields' => ['email'],

            // Country assumed for a phone typed WITHOUT a country code (an
            // ISO 3166-1 alpha-2 code, e.g. 'MX'). Empty = keep the digits as
            // given rather than inventing a country the number isn't from.
            'phone_country' => env('KINETIX_IDENTITY_PHONE_COUNTRY', ''),

            // The shape a username may take. The default excludes `@` on
            // purpose, so a username can never be mistaken for — or registered
            // as — somebody else's email address.
            'username_pattern' => '/^[a-zA-Z0-9._-]{3,32}$/',
        ],

        'passwords' => [
            // Days a password stays valid. null = passwords never expire.
            // Accounts whose password predates the policy have no timestamp and
            // count as current, so switching this on can't lock everyone out at
            // once — backfill `password_changed_at` if you want it retroactive.
            'expires_after_days' => env('KINETIX_PASSWORD_EXPIRES_DAYS'),

            // How many previous passwords may not be reused (0 = off, max 5).
            // Each one costs a hash comparison, which is slow on purpose.
            'history' => env('KINETIX_PASSWORD_HISTORY', 0),

            // How long an UNUSED temporary credential stays valid. Kinetix does
            // not own your login, so enforce it there with
            // KinetixPasswords::temporaryHasExpired($user).
            'temporary_ttl_hours' => env('KINETIX_PASSWORD_TEMPORARY_TTL', 48),

            // Days before expiry that the UI starts warning (0 = never warn).
            'warn_before_days' => env('KINETIX_PASSWORD_WARN_DAYS', 7),

            // Route names (fnmatch patterns allowed) or paths the
            // `kinetix.password` middleware lets through for a user who must
            // change their password. The change screen itself, login, logout,
            // password.* and verification.* are ALWAYS exempt — without them a
            // stuck user could neither fix it nor leave.
            'except' => [],

            'view'           => env('KINETIX_PASSWORD_VIEW', 'Kinetix/PasswordChange'),
            'redirect_after' => env('KINETIX_PASSWORD_REDIRECT', '/'),
        ],
    ],

    'membership' => [
        'enabled'           => env('KINETIX_MEMBERSHIP_ENABLED', false),
        'teams'             => env('KINETIX_MEMBERSHIP_TEAMS'), // null = inherit kinetix.teams
        'user_model'        => env('KINETIX_MEMBERSHIP_USER_MODEL', 'App\\Models\\User'),
        'assignable_roles'  => ['editor', 'viewer'],
        'activation_expiry' => env('KINETIX_MEMBERSHIP_ACTIVATION_HOURS', 72),

        // How a provisioned member gets an account:
        //   'activation' (default) — no User exists until the person sets their
        //       own password, so no password-less accounts pile up.
        //   'direct' — the User is created immediately with a temporary
        //       password the admin hands over. Trades that invariant for
        //       working with NO delivery channel, which is the point when your
        //       staff have no email address. Requires `credentials.enabled`,
        //       or the forced first-login change is not enforced.
        'provisioning' => env('KINETIX_MEMBERSHIP_PROVISIONING', 'activation'),

        // How the activation link reaches them: 'mail' (default) sends it;
        // 'sms' texts it to the member's phone; 'manual' sends nothing and
        // hands it back to the admin ONCE.
        'delivery' => env('KINETIX_MEMBERSHIP_DELIVERY', 'mail'),

        // The notification channel SMS goes out on. Kinetix does NOT pick an
        // SMS provider — Vonage, Twilio and the local gateways each register
        // their own channel, they are mutually incompatible, and which one is
        // right is a business decision about coverage and price in your
        // country. Name the channel you registered.
        'sms_channel' => env('KINETIX_MEMBERSHIP_SMS_CHANNEL', 'vonage'),

        // The activation notification. Kinetix's default ships `toMail()` plus
        // the message text (`smsContent()`); point this at a subclass adding
        // your channel's method (`toVonage()`, `toTwilio()`, …). Required for
        // SMS — without it the link is handed to the admin rather than sent.
        'activation_notification' => null,

        // Which field identifies a member: 'email' (default), 'username' or
        // 'phone'. Must be one of `credentials.identity.fields`, or a member
        // could be provisioned under something nobody can sign in with.
        'identifier'      => env('KINETIX_MEMBERSHIP_IDENTIFIER', 'email'),
        'activation_view' => env('KINETIX_MEMBERSHIP_ACTIVATION_VIEW', 'Kinetix/MemberActivation'),
        'redirect_after'  => env('KINETIX_MEMBERSHIP_REDIRECT', '/'),

        // e.g. [\App\Kinetix\SyncProvisionedMember::class, 'attach']
        'attach_member' => null,
        'detach_member' => null,
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
        'teams'     => env('KINETIX_SETTINGS_TEAMS'), // null = inherit kinetix.teams
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

        'teams'         => env('KINETIX_WEBHOOKS_TEAMS'), // null = inherit kinetix.teams
        'allow_private' => env('KINETIX_WEBHOOKS_ALLOW_PRIVATE', false),
        'timeout'       => env('KINETIX_WEBHOOKS_TIMEOUT', 10),
        'tries'         => env('KINETIX_WEBHOOKS_TRIES', 3),

        // Delivery log: every attempt is recorded automatically (no extra
        // setup — the logs table ships in kinetix-webhooks-migrations).
        // `log_payloads` stores the sent payload with each entry (turn off if
        // your events carry sensitive data); `response_limit` caps the stored
        // response body; prune with kinetix:webhooks:prune.
        'log_payloads'   => env('KINETIX_WEBHOOKS_LOG_PAYLOADS', true),
        'response_limit' => env('KINETIX_WEBHOOKS_RESPONSE_LIMIT', 1000),
        'retention_days' => env('KINETIX_WEBHOOKS_RETENTION_DAYS', 30),
    ],

    /*
    |--------------------------------------------------------------------------
    | API Request Logs (optional)
    |--------------------------------------------------------------------------
    |
    | Log requests hitting your token-authenticated API (SaaS integrations):
    | method, path, status, duration, token, ip — and optionally the request/
    | response bodies (opt-in, size-capped, sensitive keys redacted). Attach
    | the `kinetix.api-log` middleware to your API group; rows are written in
    | terminate() so logging adds no latency. View them with
    | <KinetixIntegrationLogs> (gate: `viewKinetixApiLogs`). Schedule
    | `kinetix:api-logs:prune` to keep the table bounded.
    |
    | With `teams` on, each row is attributed to the caller's team (resolved from
    | a team route segment when the API has one, else the token holder's
    | currentTeam) and the viewer scopes strictly — a NULL row is unattributed,
    | not shared. null = inherit `kinetix.teams`.
    |
    */
    'api_logs' => [
        'enabled'           => env('KINETIX_API_LOGS_ENABLED', false),
        'teams'             => env('KINETIX_API_LOGS_TEAMS'), // null = inherit kinetix.teams
        'log_request_body'  => env('KINETIX_API_LOGS_REQUEST_BODY', false),
        'log_response_body' => env('KINETIX_API_LOGS_RESPONSE_BODY', false),
        'body_limit'        => env('KINETIX_API_LOGS_BODY_LIMIT', 10240),
        'retention_days'    => env('KINETIX_API_LOGS_RETENTION_DAYS', 30),
        'redact'            => ['password', 'password_confirmation', 'secret', 'token', 'authorization'],
    ],

    /*
    |--------------------------------------------------------------------------
    | PDF Templates (optional)
    |--------------------------------------------------------------------------
    |
    | Configurable PDF document formats — the Mailable of PDFs. Subclass
    | PdfTemplate, register it (KinetixPdf::register(QuotePdf::class)) and
    | mount <KinetixPdfTemplate template="quote" /> for a live-preview
    | configurator; settings persist per template (and per team when team
    | scoping is on — `teams` inherits the global kinetix.teams).
    |
    | - `driver`: auto | spatie (spatie/laravel-pdf, Chromium fidelity) |
    |   barryvdh (barryvdh/laravel-dompdf) | dompdf (dompdf/dompdf direct).
    |   `auto` picks the first installed, in that order.
    |
    */
    'pdf' => [
        'enabled' => env('KINETIX_PDF_ENABLED', false),
        'driver'  => env('KINETIX_PDF_DRIVER', 'auto'),
        'teams'   => env('KINETIX_PDF_TEAMS'), // null = inherit kinetix.teams
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

        // Results per source. A source may override it with ->limit().
        'limit' => env('KINETIX_SPOTLIGHT_LIMIT', 5),

        // Shortest query that fans out to the sources. One character matches
        // nearly every row of every source, and it is the first thing every
        // user types — enforced on the endpoint AND in the palette.
        'min_chars' => env('KINETIX_SPOTLIGHT_MIN_CHARS', 2),

        // Rate limit for the search endpoint ('requests,minutes'), since one
        // request fans out across every authorized source. Null removes it.
        'throttle' => env('KINETIX_SPOTLIGHT_THROTTLE', '60,1'),

        // Directory (+ namespace) auto-scanned for `SpotlightSource` classes,
        // additive to sources registered via `KinetixSpotlight::register()`.
        // Set to null to disable discovery.
        'discover_path'      => app_path('Kinetix/Spotlight'),
        'discover_namespace' => 'App\\Kinetix\\Spotlight',
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

        // Ship the checklist state on every Inertia response, so <KinetixOnboardingChecklist>
        // renders from the page payload instead of fetching on mount (a round-trip per
        // navigation when the layout isn't persistent — and the sidebar variant is mounted
        // on every page). The cost is one progress-row read plus your `completedUsing`
        // callbacks per response, so keep those cheap: they run on EVERY request, not only
        // where the checklist is mounted. Turn it off to trade that back for the fetch.
        'share' => env('KINETIX_ONBOARDING_SHARE', true),

        // Track progress per team instead of per user.
        'teams' => env('KINETIX_ONBOARDING_TEAMS'), // null = inherit kinetix.teams
    ],

    /*
    |--------------------------------------------------------------------------
    | Product Tours (optional)
    |--------------------------------------------------------------------------
    |
    | Guided, spotlight-style tours rendered by driver.js (installed in the
    | host: `kinetix:install --tours`) with Kinetix's shadcn theme. Declare
    | tours per module with KinetixTours::tour(...) in a service provider and
    | mount one global <KinetixTours /> in your layout — it auto-starts the
    | unseen tour matching the current page. `driver` picks where "seen" is
    | remembered: the browser (local) or per-user in the database (survives
    | devices; publish kinetix-tours-migrations first).
    |
    */
    'tours' => [
        'enabled' => env('KINETIX_TOURS_ENABLED', false),

        // 'local' (localStorage) or 'database' (per-user, seen/reset endpoints).
        'driver' => env('KINETIX_TOURS_DRIVER', 'local'),
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

        // How many entries the "what's new" feed returns when the request
        // doesn't ask for its own `?limit=` (hard ceiling: 50).
        'feed_limit' => env('KINETIX_ANNOUNCEMENTS_FEED_LIMIT', 20),

        // How many entries <KinetixAnnouncementBanner> rotates through when the
        // component doesn't pass its own `limit` (hard ceiling: 10).
        'banner_limit' => env('KINETIX_ANNOUNCEMENTS_BANNER_LIMIT', 3),

        // Ship the unread count + banner feed on every Inertia response, so the
        // header trigger and the banner render from the page payload instead of
        // each fetching on mount (a round-trip per navigation when the layout
        // isn't persistent). Turn it off to trade that back for a per-response
        // query and have the components fetch for themselves.
        'share' => env('KINETIX_ANNOUNCEMENTS_SHARE', true),

        // With teams on, an announcement belongs to the team it was published
        // from; a NULL team is platform-wide (every feed shows it), which is
        // what `KinetixAnnouncements::publishGlobally()` writes.
        'teams' => env('KINETIX_ANNOUNCEMENTS_TEAMS'), // null = inherit kinetix.teams
    ],

    /*
    |--------------------------------------------------------------------------
    | Help Center (optional)
    |--------------------------------------------------------------------------
    |
    | In-app help pages rendered from markdown articles in `path` (front matter:
    | title / permission / icon / order / group; locale variants via
    | `{slug}.{locale}.md`). Articles — and `<!-- kinetix:can ability -->`
    | blocks inside them — are hidden from users the Gate denies. Screenshots
    | are captured with `php artisan kinetix:help-screenshots` (requires
    | Playwright in the host app) and stored on `screenshots.disk`
    | (null = inherit kinetix.filesystem.disk).
    |
    */
    'help' => [
        'enabled' => env('KINETIX_HELP_ENABLED', false),

        // Markdown articles directory (host-owned, git-versioned).
        'path' => env('KINETIX_HELP_PATH'),

        // The host's named article route Spotlight links resolve through.
        'show_route' => 'help.show',

        // Locales the manual may be served in (`{slug}.{locale}.md` variants).
        // Null = infer: the Locale module's locales, else whatever variants
        // exist on disk. Requests may only ask for a locale on this list.
        'locales' => null,

        // The language the base `{slug}.md` files are written in, and the last
        // resort before serving the base file. Null = config('app.fallback_locale').
        'fallback_locale' => env('KINETIX_HELP_FALLBACK_LOCALE'),

        // Strict mode: hide articles that have no variant in the active locale
        // instead of serving them in the fallback language.
        'hide_untranslated' => env('KINETIX_HELP_HIDE_UNTRANSLATED', false),

        // Cache the per-locale article index — metadata + the plain-text
        // search corpus (never the rendered, permission-gated HTML).
        'cache' => [
            'enabled' => env('KINETIX_HELP_CACHE', false),
            'ttl'     => env('KINETIX_HELP_CACHE_TTL', 3600),

            // 'fingerprint' = key on the files' mtimes, so edits invalidate
            // instantly (best for staging/authoring). 'ttl' = skip the
            // per-request stat of every file and expire on the TTL (best for
            // production, where articles ship with a deploy).
            'strategy' => env('KINETIX_HELP_CACHE_STRATEGY', 'fingerprint'),
        ],

        'screenshots' => [
            // null = inherit kinetix.filesystem.disk.
            'disk' => env('KINETIX_HELP_SCREENSHOT_DISK'),

            // Key prefix on the disk.
            'path_prefix' => 'help/screenshots',

            // How long (seconds) a browser may reuse a capture before
            // revalidating. Captures stream through an AUTHENTICATED route, so
            // the header is always `private` — a shared proxy or CDN must never
            // hold one. An ETag rides along, so an expired copy costs a 304
            // instead of the bytes. 0 disables caching entirely (a live demo
            // environment where captures are regenerated constantly).
            'cache_ttl' => env('KINETIX_HELP_SCREENSHOT_CACHE_TTL', 86400),

            // Pages to capture: name => path (or ['path' => ..., 'full_page' => bool, 'delay' => ms]).
            'pages' => [
                // 'dashboard' => '/dashboard',
            ],

            'base_url' => env('KINETIX_HELP_SCREENSHOT_BASE_URL'),
            'viewport' => ['width' => 1440, 'height' => 900],

            // Settle delay after load, in ms (websockets keep networkidle busy).
            'delay' => 700,

            // Screenshot user credentials (use a dedicated, 2FA-free account).
            'credentials' => [
                'email'    => env('KINETIX_SCREENSHOT_EMAIL', ''),
                'password' => env('KINETIX_SCREENSHOT_PASSWORD', ''),
            ],

            // Login flow selectors — adjust to your app's login form.
            'selectors' => [
                'email'         => '#email',
                'password'      => '#password',
                'submit'        => 'button[type=submit]',
                'logged_in_url' => '**/dashboard',
            ],

            'node_binary' => env('KINETIX_HELP_NODE_BINARY', 'node'),
        ],
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
    'tables' => [
        // Locale used to format numeric column summaries (Sum/Average/Range).
        // Null uses the app locale; e.g. 'de-DE' for European grouping.
        'number_locale' => env('KINETIX_TABLES_NUMBER_LOCALE'),

        // Where an in-table edit modal (simple resources, Table::recordModals())
        // reads a record: 'server' fetches a fresh copy so concurrent edits are
        // never lost; 'row' prefills from the already-loaded row (no round-trip,
        // but shows the value as of the last table load). Per-table override:
        // ->recordModals(Resource::class, source: 'row').
        'record_source' => env('KINETIX_TABLES_RECORD_SOURCE', 'server'),

        // How long (minutes) a table's signed write descriptor stays valid. The
        // descriptor authorizes inline cell edits, reordering and kanban moves,
        // and is bound to the user it was minted for, so this bounds the replay
        // window of a token captured from a long-lived page. Beyond it the
        // endpoints answer 403 and the page must be reloaded. Null disables
        // expiry (not recommended).
        'token_ttl' => env('KINETIX_TABLES_TOKEN_TTL', 1440),

        // Hard ceiling on the `perPage` a request may ask for, so a crafted
        // ?perPage=10000000 can't hydrate a whole table into one payload.
        'max_per_page' => env('KINETIX_TABLES_MAX_PER_PAGE', 200),
    ],

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

        // How many rows a `relationship()` Select / CheckboxList / Radio (and
        // SelectFilter) loads eagerly into the page payload. Past this the list
        // is truncated and a warning is logged — declare the field
        // `searchable()` instead so options are fetched on demand.
        'relationship_options_limit' => env('KINETIX_RELATIONSHIP_OPTIONS_LIMIT', 200),
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
        'teams' => env('KINETIX_WIZARDS_TEAMS'), // null = inherit kinetix.teams

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
        'teams'   => env('KINETIX_FEATURES_TEAMS'), // null = inherit kinetix.teams
    ],

    /*
    |--------------------------------------------------------------------------
    | Entitlements (optional)
    |--------------------------------------------------------------------------
    |
    | Composes the gating layers Kinetix already ships — feature flags, plan
    | capabilities, plan usage limits and role permissions — under one declared
    | name, so a feature that sits behind several of them is described once and
    | evaluated the same way everywhere (controller, middleware, button, menu).
    |
    | Declare them in a service provider:
    |
    |     KinetixEntitlements::define('projects.create')
    |         ->plan('projects')
    |         ->limit('projects', [ProjectCounter::class, 'for'])
    |         ->permission('projects.create');
    |
    | Turning this on only enables the `kinetix_entitlements` Inertia prop that
    | feeds the frontend helpers; `KinetixEntitlements::allows()` and the
    | `kinetix.entitled` middleware work regardless.
    |
    */
    'entitlements' => [
        'enabled' => env('KINETIX_ENTITLEMENTS_ENABLED', false),
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

        // Permissions that make a user off-limits to impersonate unless the
        // impersonator holds them too. Impersonating a session means inheriting
        // it, so without this `users.impersonate` can be laundered into role
        // management by impersonating whoever holds it. Add any of your own
        // abilities that grant privileges to others.
        'protected_permissions' => ['roles.manage'],
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

        'teams'          => env('KINETIX_ACTIVITY_TEAMS'), // null = inherit kinetix.teams
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
        'teams'         => env('KINETIX_BILLING_TEAMS'), // null = inherit kinetix.teams
        'trial_generic' => env('KINETIX_BILLING_TRIAL_GENERIC', false),
        'billable'      => env('KINETIX_BILLING_BILLABLE', 'App\\Models\\User'),
        'plan_model'    => env('KINETIX_BILLING_PLAN_MODEL', 'Happones\\Kinetix\\Billing\\Plan'),

        // Cashier subscription "type" (Cashier's default is 'default').
        'subscription' => env('KINETIX_BILLING_SUBSCRIPTION', 'default'),

        // The `plans` table is read as an in-memory catalog: every plan
        // question (capabilities, usage limits, the free fallback) is answered
        // from it, and it is loaded AT MOST ONCE PER REQUEST regardless of
        // these settings.
        //
        // Setting a `ttl` (seconds) adds a persistent layer on top, so the
        // catalog survives between requests and plan gating costs zero
        // queries. Writes through the Plan model flush it automatically, so an
        // edit still applies on the next request; leave `ttl` null if plans
        // are written by something Eloquent never sees (raw SQL, an external
        // admin). Point `store` at a SHARED store (redis/memcached) on
        // multi-server setups — a per-server store would go stale on the
        // other nodes. Null = the default cache store.
        'cache' => [
            'store' => env('KINETIX_BILLING_CACHE_STORE'),
            'ttl'   => env('KINETIX_BILLING_CACHE_TTL'),
        ],

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

        // Where the `kinetix.plan` middleware and the <KinetixPlanGate> upsell
        // send users to upgrade (e.g. '/billing'). Null = plain 403 / no CTA.
        'upgrade_url' => env('KINETIX_BILLING_UPGRADE_URL'),

        // App-wide defaults for the <KinetixPlanLock> upsell UI. Every entry is
        // a default only — a per-instance prop always wins.
        'lock' => [
            // How a locked feature is presented: card | overlay | banner | badge.
            'variant' => env('KINETIX_BILLING_LOCK_VARIANT', 'card'),

            // Whether the lock CTA opens the upgrade modal (false = link out
            // to `upgrade_url` directly).
            'modal' => env('KINETIX_BILLING_LOCK_MODAL', true),

            // Whether the `overlay` variant blurs the content behind the lock.
            'blur' => env('KINETIX_BILLING_LOCK_BLUR', true),

            // Plan pill shown next to the lock title (e.g. 'Pro'). Null = none.
            'badge_label' => env('KINETIX_BILLING_LOCK_BADGE_LABEL'),
        ],

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
    | With `teams` on, a template with a NULL team is a GLOBAL default every
    | tenant sees; a team editing one forks it into its own override (same key,
    | its own row) and the resolver prefers the override. null = inherit
    | `kinetix.teams`.
    |
    */
    'mail_templates' => [
        'enabled' => env('KINETIX_MAIL_TEMPLATES_ENABLED', false),
        'teams'   => env('KINETIX_MAIL_TEMPLATES_TEAMS'), // null = inherit kinetix.teams
    ],

    /*
    |--------------------------------------------------------------------------
    | Cookie Consent (optional)
    |--------------------------------------------------------------------------
    |
    | A shadcn-styled cookie consent bar — mount <KinetixCookieConsent /> once
    | in your layout. It shows until the visitor accepts or declines, then
    | writes a plain browser cookie (no server round-trip) and stays hidden.
    | A simple accept/decline bar, not a granular per-category consent manager.
    |
    */
    'cookie_consent' => [
        'enabled' => env('KINETIX_COOKIE_CONSENT_ENABLED', false),

        // Name of the browser cookie recording the visitor's choice.
        'cookie_name' => env('KINETIX_COOKIE_CONSENT_COOKIE_NAME', 'kinetix_cookie_consent'),

        // How long the choice is remembered before the bar reappears.
        'expiry_days' => env('KINETIX_COOKIE_CONSENT_EXPIRY_DAYS', 365),

        // 'bottom' | 'top'.
        'position' => env('KINETIX_COOKIE_CONSENT_POSITION', 'bottom'),

        // Optional link to your cookie/privacy policy page, shown in the bar.
        'policy_url' => env('KINETIX_COOKIE_CONSENT_POLICY_URL'),
    ],

    /*
    |--------------------------------------------------------------------------
    | Reports Center (optional)
    |--------------------------------------------------------------------------
    |
    | Large-dataset CSV/XLSX report generation: queued, DB-tracked (live
    | progress, cancellable, retryable), and schedulable (one-off or
    | recurring). Distinct from the lightweight, email-only `reports` block
    | above — this is the productized version with a launcher UI, a runs
    | table ("failed jobs"-style: download/cancel/retry), and a scheduled
    | reports list. Define report TYPES by extending
    | `Happones\Kinetix\ReportsCenter\Report` (reuses the Exporter machinery
    | for chunked data access) in `discover_path` (auto-discovered — no
    | manual registration needed) or register others manually via
    | `KinetixReportsCenter::register()`.
    |
    */
    'reports_center' => [
        'enabled' => env('KINETIX_REPORTS_CENTER_ENABLED', false),

        // Directory (+ namespace) auto-scanned for `Report` subclasses.
        'discover_path'      => app_path('Kinetix/Reports'),
        'discover_namespace' => 'App\\Kinetix\\Reports',

        // Frontend poll interval (ms) for the runs/schedules widgets.
        'poll' => env('KINETIX_REPORTS_CENTER_POLL', 5000),

        // Days a completed run's row + generated file are kept before
        // `kinetix:report-runs:prune` removes them. Also used to compute
        // each run's `expires_at` (completed_at + retention_days).
        'retention_days' => env('KINETIX_REPORTS_CENTER_RETENTION_DAYS', 7),
    ],

    /*
    |--------------------------------------------------------------------------
    | Confidential Fields (optional)
    |--------------------------------------------------------------------------
    |
    | Field-level encryption + masking for Eloquent attributes. Add
    | `ConfidentialCast` to a model's `casts()` to encrypt a string column at
    | rest and mask it on read (e.g. `••••6789`) until the current session
    | unlocks it via password confirmation. Enforcement lives in the cast
    | itself, so every consumer (Table, Infolist, exports, tinker) sees the
    | already-masked-or-real value with zero extra work per surface.
    |
    | - `key_manager`: 'local' (wraps keys via the app's own APP_KEY, zero
    |   network calls) or a class implementing
    |   Happones\Kinetix\Confidential\KeyManagers\KeyManager (e.g. your own
    |   AWS/GCP KMS or Vault Transit binding).
    | - `reveal_ttl_minutes`: how long an unlock lasts before re-confirming.
    | - `key_cache_ttl_minutes`: how long an unwrapped data key stays cached
    |   before the key manager is asked to unwrap it again — this is what
    |   keeps a KMS-backed driver from being called once per field/row.
    |
    */
    'confidential' => [
        'enabled' => env('KINETIX_CONFIDENTIAL_ENABLED', false),

        'key_manager' => env('KINETIX_CONFIDENTIAL_KEY_MANAGER', 'local'),

        'reveal_ttl_minutes' => env('KINETIX_CONFIDENTIAL_REVEAL_TTL', 5),

        'require_password' => env('KINETIX_CONFIDENTIAL_REQUIRE_PASSWORD', true),

        // Default trailing characters shown when masked (per-field
        // overridable via `ConfidentialCast::class.':<visible>,<head|tail>'`).
        'mask_visible' => env('KINETIX_CONFIDENTIAL_MASK_VISIBLE', 4),

        'key_cache_ttl_minutes' => env('KINETIX_CONFIDENTIAL_KEY_CACHE_TTL', 10),
    ],

];
