<?php

declare(strict_types=1);
use Happones\Kinetix\Billing\Plan;

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
        'enabled'    => env('KINETIX_BILLING_ENABLED', false),
        'billable'   => env('KINETIX_BILLING_BILLABLE', 'App\\Models\\User'),
        'plan_model' => env('KINETIX_BILLING_PLAN_MODEL', Plan::class),

        // Cashier subscription "type" (Cashier's default is 'default').
        'subscription' => env('KINETIX_BILLING_SUBSCRIPTION', 'default'),

        // Currency symbol used when formatting prices in the UI.
        'currency'        => env('KINETIX_BILLING_CURRENCY', 'USD'),
        'currency_symbol' => env('KINETIX_BILLING_CURRENCY_SYMBOL', '$'),

        // Product label shown on downloaded invoices.
        'product' => env('KINETIX_BILLING_PRODUCT', 'Subscription'),

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

];
