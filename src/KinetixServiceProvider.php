<?php

declare(strict_types=1);

namespace Happones\Kinetix;

use Happones\Kinetix\Accessibility\AccessibilityController;
use Happones\Kinetix\Accessibility\AccessibilityManager;
use Happones\Kinetix\Activity\ActivityController;
use Happones\Kinetix\Activity\ActivityLogger;
use Happones\Kinetix\Billing\BillingRoutes;
use Happones\Kinetix\Billing\Middleware\PlanFeatureMiddleware;
use Happones\Kinetix\Commands\ActivityPruneCommand;
use Happones\Kinetix\Commands\InstallCommand;
use Happones\Kinetix\Commands\MakeActionCommand;
use Happones\Kinetix\Commands\MakeBillingCommand;
use Happones\Kinetix\Commands\MakeExporterCommand;
use Happones\Kinetix\Commands\MakeFormCommand;
use Happones\Kinetix\Commands\MakeImporterCommand;
use Happones\Kinetix\Commands\MakeInfolistCommand;
use Happones\Kinetix\Commands\MakeNotificationCommand;
use Happones\Kinetix\Commands\MakeRelationManagerCommand;
use Happones\Kinetix\Commands\MakeResourceCommand;
use Happones\Kinetix\Commands\MakeSettingsPageCommand;
use Happones\Kinetix\Commands\MakeTableCommand;
use Happones\Kinetix\Commands\PermissionsSyncCommand;
use Happones\Kinetix\Commands\SendNotificationCommand;
use Happones\Kinetix\Commands\WebhooksPruneCommand;
use Happones\Kinetix\ConnectedAccounts\ConnectedAccountController;
use Happones\Kinetix\ConnectedAccounts\ConnectedAccountManager;
use Happones\Kinetix\ConnectedAccounts\ConnectedAccountProviderRegistry;
use Happones\Kinetix\Data\AccessibilityData;
use Happones\Kinetix\Exports\ExportController;
use Happones\Kinetix\Features\FeatureManager;
use Happones\Kinetix\Features\Middleware\EnsureFeature;
use Happones\Kinetix\Forms\SearchController;
use Happones\Kinetix\Forms\UploadController;
use Happones\Kinetix\Gdpr\GdprController;
use Happones\Kinetix\Gdpr\GdprManager;
use Happones\Kinetix\Gdpr\GdprRegistry;
use Happones\Kinetix\Impersonation\ImpersonationController;
use Happones\Kinetix\Impersonation\ImpersonationManager;
use Happones\Kinetix\Impersonation\Middleware\DenyWhileImpersonating;
use Happones\Kinetix\Imports\ImportController;
use Happones\Kinetix\Membership\MembershipController;
use Happones\Kinetix\Onboarding\OnboardingController;
use Happones\Kinetix\Onboarding\OnboardingManager;
use Happones\Kinetix\Onboarding\OnboardingStepRegistry;
use Happones\Kinetix\Permissions\Middleware\SetPermissionsTeam;
use Happones\Kinetix\Permissions\PermissionController;
use Happones\Kinetix\Permissions\PermissionRegistry;
use Happones\Kinetix\Sessions\BrowserSessionManager;
use Happones\Kinetix\Sessions\SessionController;
use Happones\Kinetix\Settings\KinetixSettings;
use Happones\Kinetix\Settings\SettingsController;
use Happones\Kinetix\Settings\SettingsManager;
use Happones\Kinetix\Settings\SettingsPage;
use Happones\Kinetix\Settings\SettingsRegistry;
use Happones\Kinetix\Spotlight\SpotlightController;
use Happones\Kinetix\Spotlight\SpotlightRegistry;
use Happones\Kinetix\Tokens\TokenController;
use Happones\Kinetix\Tokens\TokenScopeRegistry;
use Happones\Kinetix\Webhooks\LogSpatieWebhookCall;
use Happones\Kinetix\Webhooks\WebhookController;
use Happones\Kinetix\Webhooks\WebhookDispatcher;
use Happones\Kinetix\Webhooks\WebhookEventRegistry;
use Happones\Kinetix\Wizards\Middleware\EnsureWizardCompleted;
use Happones\Kinetix\Wizards\WizardController;
use Happones\Kinetix\Wizards\WizardManager;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Spatie\Permission\PermissionRegistrar;
use Spatie\WebhookServer\Events\WebhookCallFailedEvent;
use Spatie\WebhookServer\Events\WebhookCallSucceededEvent;

class KinetixServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../config/kinetix.php',
            'kinetix'
        );

        // The permission registry accumulates feature definitions app-wide.
        $this->app->singleton(PermissionRegistry::class);

        // The settings store + page registry are app-wide singletons.
        $this->app->singleton(SettingsManager::class);
        $this->app->singleton(SettingsRegistry::class);

        // The activity logger (audit trail + event spine).
        $this->app->singleton(ActivityLogger::class);

        // The impersonation manager (log in as user).
        $this->app->singleton(ImpersonationManager::class);

        // The feature-flag manager (pennant bridge / native evaluator).
        $this->app->singleton(FeatureManager::class);

        // The spotlight source registry (command palette).
        $this->app->singleton(SpotlightRegistry::class);

        // The webhook event registry + dispatcher.
        $this->app->singleton(WebhookEventRegistry::class);
        $this->app->singleton(WebhookDispatcher::class);

        // The developer-token scope registry, seeded from config.
        $this->app->singleton(TokenScopeRegistry::class, function (): TokenScopeRegistry {
            $registry = new TokenScopeRegistry;
            $registry->register((array) config('kinetix.tokens.scopes', []));

            return $registry;
        });

        // The onboarding step registry + checklist manager.
        $this->app->singleton(OnboardingStepRegistry::class);
        $this->app->singleton(OnboardingManager::class);

        // The wizard completion manager (backs the gating middleware).
        $this->app->singleton(WizardManager::class);

        // The GDPR data-section registry + manager.
        $this->app->singleton(GdprRegistry::class);
        $this->app->singleton(GdprManager::class);

        // The accessibility preferences manager.
        $this->app->singleton(AccessibilityManager::class);

        // The connected-accounts provider registry (seeded from config) + manager.
        $this->app->singleton(ConnectedAccountProviderRegistry::class, function (): ConnectedAccountProviderRegistry {
            $registry = new ConnectedAccountProviderRegistry;
            $registry->register((array) config('kinetix.connected_accounts.providers', []));

            return $registry;
        });
        $this->app->singleton(ConnectedAccountManager::class);

        // The browser-sessions manager (reads Laravel's sessions table).
        $this->app->singleton(BrowserSessionManager::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->validateEnvironment();

        // Register package translation namespace
        $this->loadTranslationsFrom(__DIR__.'/../resources/lang', 'kinetix');

        if ($this->app->runningInConsole()) {
            $this->commands([
                MakeNotificationCommand::class,
                SendNotificationCommand::class,
                MakeResourceCommand::class,
                MakeActionCommand::class,
                MakeTableCommand::class,
                MakeFormCommand::class,
                MakeInfolistCommand::class,
                MakeImporterCommand::class,
                MakeExporterCommand::class,
                MakeRelationManagerCommand::class,
                MakeBillingCommand::class,
                MakeSettingsPageCommand::class,
                ActivityPruneCommand::class,
                WebhooksPruneCommand::class,
                PermissionsSyncCommand::class,
                InstallCommand::class,
            ]);

            // Publish config
            $this->publishes([
                __DIR__.'/../config/kinetix.php' => config_path('kinetix.php'),
            ], 'kinetix-config');

            // Publish components
            $this->publishes([
                __DIR__.'/../resources/js/components'  => resource_path('js/components/kinetix'),
                __DIR__.'/../resources/js/composables' => resource_path('js/composables'),
                __DIR__.'/../resources/js/stores'      => resource_path('js/stores'),
                __DIR__.'/../resources/js/types'       => resource_path('js/types'),
            ], 'kinetix-components');

            // Publish translations directly into Laravel lang directory so generators can pick them up
            $this->publishes([
                __DIR__.'/../resources/lang' => lang_path(),
            ], 'kinetix-translations');

            // Publish the fallback shadcn design tokens (only needed if the app
            // doesn't already define them, e.g. outside a shadcn-vue starter kit).
            $this->publishes([
                __DIR__.'/../resources/css/kinetix.css' => resource_path('css/kinetix.css'),
            ], 'kinetix-styles');

            // Publish the optional Billing module's plans migration.
            $this->publishes([
                __DIR__.'/../database/migrations/2026_01_01_000000_create_kinetix_plans_table.php' => database_path('migrations/2026_01_01_000000_create_kinetix_plans_table.php'),
            ], 'kinetix-billing-migrations');

            // Publish the optional Membership module's provisions migration.
            $this->publishes([
                __DIR__.'/../database/migrations/2026_01_01_000001_create_kinetix_member_provisions_table.php' => database_path('migrations/2026_01_01_000001_create_kinetix_member_provisions_table.php'),
            ], 'kinetix-membership-migrations');

            // Publish the optional Settings module's migration.
            $this->publishes([
                __DIR__.'/../database/migrations/2026_01_01_000002_create_kinetix_settings_table.php' => database_path('migrations/2026_01_01_000002_create_kinetix_settings_table.php'),
            ], 'kinetix-settings-migrations');

            // Publish the optional Activity module's migration.
            $this->publishes([
                __DIR__.'/../database/migrations/2026_01_01_000003_create_kinetix_activity_table.php' => database_path('migrations/2026_01_01_000003_create_kinetix_activity_table.php'),
            ], 'kinetix-activity-migrations');

            // Publish the optional Webhooks module's migrations.
            $this->publishes([
                __DIR__.'/../database/migrations/2026_01_01_000004_create_kinetix_webhook_endpoints_table.php' => database_path('migrations/2026_01_01_000004_create_kinetix_webhook_endpoints_table.php'),
                __DIR__.'/../database/migrations/2026_01_01_000005_create_kinetix_webhook_logs_table.php'      => database_path('migrations/2026_01_01_000005_create_kinetix_webhook_logs_table.php'),
            ], 'kinetix-webhooks-migrations');

            // Publish the optional Onboarding module's migration.
            $this->publishes([
                __DIR__.'/../database/migrations/2026_01_01_000006_create_kinetix_onboarding_table.php' => database_path('migrations/2026_01_01_000006_create_kinetix_onboarding_table.php'),
            ], 'kinetix-onboarding-migrations');

            // Publish the optional Wizards module's completion migration.
            $this->publishes([
                __DIR__.'/../database/migrations/2026_01_01_000007_create_kinetix_wizard_completions_table.php' => database_path('migrations/2026_01_01_000007_create_kinetix_wizard_completions_table.php'),
            ], 'kinetix-wizards-migrations');

            // Publish the optional Accessibility module's migration.
            $this->publishes([
                __DIR__.'/../database/migrations/2026_01_01_000008_create_kinetix_accessibility_table.php' => database_path('migrations/2026_01_01_000008_create_kinetix_accessibility_table.php'),
            ], 'kinetix-accessibility-migrations');

            // Publish the optional Connected Accounts module's migration.
            $this->publishes([
                __DIR__.'/../database/migrations/2026_01_01_000009_create_kinetix_connected_accounts_table.php' => database_path('migrations/2026_01_01_000009_create_kinetix_connected_accounts_table.php'),
            ], 'kinetix-connected-accounts-migrations');

            // Publish public assets (sounds, etc.)
            $this->publishes([
                __DIR__.'/../public' => public_path('vendor/kinetix'),
            ], 'kinetix-assets');
        }

        // Register endpoints for database notifications actions
        $this->registerNotificationRoutes();

        // Register endpoints for table inline edits
        $this->registerTableRoutes();

        // Register endpoints for the import preview & dispatch flow
        $this->registerImportRoutes();

        // Register the export download endpoint
        $this->registerExportRoutes();

        // Register the file-upload endpoints used by the FileUpload field
        $this->registerUploadRoutes();

        // Register the optional Billing module (middleware alias + opt-in routes)
        $this->registerBilling();

        // Register the optional Permissions module (super-admin gate + team middleware)
        $this->registerPermissions();

        // Register the optional Membership module (admin provisioning + activation)
        $this->registerMembership();

        // Register the optional Settings module (database-backed settings pages)
        $this->registerSettings();

        // Register the optional Activity module (audit trail + event spine)
        $this->registerActivity();

        // Register the optional Impersonation module (log in as user)
        $this->registerImpersonation();

        // Register the optional Feature Flags module (pennant bridge / native)
        $this->registerFeatures();

        // Register the optional Spotlight module (Cmd+K command palette)
        $this->registerSpotlight();

        // Register the optional Webhooks module (outbound event delivery)
        $this->registerWebhooks();

        $this->registerTokens();

        // Register the optional Onboarding module (first-run checklist)
        $this->registerOnboarding();

        // Register the optional Wizards module (gating middleware + completion)
        $this->registerWizards();

        // Register the optional GDPR module (export my data + account deletion)
        $this->registerGdpr();

        // Register the optional Accessibility module (per-user a11y preferences)
        $this->registerAccessibility();

        // Register the optional Connected Accounts module (social auth + linking)
        $this->registerConnectedAccounts();

        // Register the optional Browser Sessions module (device management)
        $this->registerSessions();

        // Share notifications and active config with Inertia
        if (class_exists(Inertia::class)) {
            $this->shareInertiaData();
        }
    }

    /**
     * Wire the optional Permissions module. Enforcement still flows through
     * Laravel's Gate (Kinetix actions already use it); this only adds the
     * super-admin bypass and the spatie team-id bridge.
     */
    protected function registerPermissions(): void
    {
        // The team-id bridge middleware is always aliased; it no-ops unless
        // `kinetix.permissions.teams` is on and spatie is installed.
        if (class_exists(PermissionRegistrar::class)) {
            $this->app['router']->aliasMiddleware(
                'kinetix.permissions.team',
                SetPermissionsTeam::class,
            );
        }

        if (! config('kinetix.permissions.enabled', false)) {
            return;
        }

        // The built-in feature that guards the role-management endpoints/UI.
        app(PermissionRegistry::class)->feature('roles')->label('Roles & Permissions')->ability('manage', 'Manage roles');

        // A super-admin role bypasses every gate check.
        $superAdmin = (string) config('kinetix.permissions.super_admin_role', 'super-admin');

        if ($superAdmin !== '') {
            Gate::before(function ($user, string $ability) use ($superAdmin): ?bool {
                if (method_exists($user, 'hasRole') && $user->hasRole($superAdmin)) {
                    return true;
                }

                return null;
            });
        }

        $this->registerPermissionRoutes();
    }

    /**
     * Register the role-management endpoints (teams-aware prefix, team middleware,
     * gated by `roles.manage`). Only when the feature is enabled and spatie is present.
     */
    protected function registerPermissionRoutes(): void
    {
        if (! class_exists(PermissionRegistrar::class)) {
            return;
        }

        $prefix     = config('kinetix.route_prefix', '_kinetix');
        $middleware = config('kinetix.middleware', ['web', 'auth']);

        if (config('kinetix.teams', false)) {
            $prefix       = '{current_team}/'.$prefix;
            $middleware[] = 'kinetix.permissions.team';
        }

        Route::middleware($middleware)
            ->prefix("{$prefix}/permissions")
            ->group(function () {
                Route::get('features', [PermissionController::class, 'features'])
                    ->name('kinetix.permissions.features');
                Route::get('roles', [PermissionController::class, 'roles'])
                    ->name('kinetix.permissions.roles');
                Route::post('roles', [PermissionController::class, 'store'])
                    ->name('kinetix.permissions.roles.store');
                Route::put('roles/{role}', [PermissionController::class, 'update'])
                    ->name('kinetix.permissions.roles.update');
                Route::delete('roles/{role}', [PermissionController::class, 'destroy'])
                    ->name('kinetix.permissions.roles.destroy');
            });
    }

    /**
     * Wire the optional Membership module: an admin-provisioned alternative to
     * self-serve team invitations. Registers the `members.*` abilities (so they
     * appear in the permission matrix / sync) and the management + activation
     * routes. Authorization flows through the Gate exactly like `roles.manage`.
     */
    protected function registerMembership(): void
    {
        if (! config('kinetix.membership.enabled', false)) {
            return;
        }

        app(PermissionRegistry::class)->feature('members')
            ->label('Members')
            ->abilities([
                'viewAny'   => 'View members',
                'provision' => 'Add / invite members',
                'update'    => 'Change member role',
                'revoke'    => 'Remove members',
            ]);

        $this->registerMembershipRoutes();
    }

    /**
     * Register the membership management endpoints (teams-aware prefix, gated by
     * `members.*`) and the public, signed activation endpoints (no auth).
     */
    protected function registerMembershipRoutes(): void
    {
        $prefix     = config('kinetix.route_prefix', '_kinetix');
        $middleware = config('kinetix.middleware', ['web', 'auth']);

        if (config('kinetix.teams', false)) {
            $prefix = '{current_team}/'.$prefix;

            if (class_exists(PermissionRegistrar::class)) {
                $middleware[] = 'kinetix.permissions.team';
            }
        }

        Route::middleware($middleware)
            ->prefix("{$prefix}/members")
            ->group(function () {
                Route::get('/', [MembershipController::class, 'index'])
                    ->name('kinetix.members.index');
                Route::post('/', [MembershipController::class, 'store'])
                    ->name('kinetix.members.store');
                Route::post('{provision}/resend', [MembershipController::class, 'resend'])
                    ->name('kinetix.members.resend');
                Route::put('{provision}', [MembershipController::class, 'update'])
                    ->name('kinetix.members.update');
                Route::delete('{provision}', [MembershipController::class, 'destroy'])
                    ->name('kinetix.members.destroy');
            });

        // Public set-password flow. GET and POST share the same path so a single
        // temporary signed URL validates for both (the form posts back to itself).
        Route::middleware(['web', 'signed'])->group(function () {
            Route::get('members/activate/{provision}', [MembershipController::class, 'showActivation'])
                ->name('kinetix.membership.activate.show');
            Route::post('members/activate/{provision}', [MembershipController::class, 'activate'])
                ->name('kinetix.membership.activate');
        });
    }

    /**
     * Wire the optional Settings module: a database-backed, class-based settings
     * panel built on the Forms engine. Registers the `settings.manage` ability,
     * the host-declared pages, and the management routes.
     */
    protected function registerSettings(): void
    {
        if (! config('kinetix.settings.enabled', false)) {
            return;
        }

        app(PermissionRegistry::class)->feature('settings')
            ->label('Settings')
            ->ability('manage', 'Manage settings');

        /** @var array<int, class-string<SettingsPage>> $pages */
        $pages = (array) config('kinetix.settings.pages', []);

        if ($pages !== []) {
            KinetixSettings::pages($pages);
        }

        $this->registerSettingsRoutes();
    }

    /**
     * Register the settings endpoints (teams-aware prefix, gated by
     * `settings.manage`). `index`/`show` render the configured Inertia view;
     * `update` persists a page's form and returns JSON.
     */
    protected function registerSettingsRoutes(): void
    {
        $prefix     = config('kinetix.route_prefix', '_kinetix');
        $middleware = config('kinetix.middleware', ['web', 'auth']);

        if (config('kinetix.teams', false)) {
            $prefix = '{current_team}/'.$prefix;

            if (class_exists(PermissionRegistrar::class)) {
                $middleware[] = 'kinetix.permissions.team';
            }
        }

        Route::middleware($middleware)
            ->prefix("{$prefix}/settings")
            ->group(function () {
                Route::get('/', [SettingsController::class, 'index'])
                    ->name('kinetix.settings.index');
                Route::get('{page}', [SettingsController::class, 'show'])
                    ->name('kinetix.settings.show');
                Route::put('{page}', [SettingsController::class, 'update'])
                    ->name('kinetix.settings.update');
            });
    }

    /**
     * Wire the optional Activity module: a team-scoped audit trail and event
     * spine. Registers the `activity.view` ability and the read endpoint.
     */
    protected function registerActivity(): void
    {
        if (! config('kinetix.activity.enabled', false)) {
            return;
        }

        app(PermissionRegistry::class)->feature('activity')
            ->label('Activity Log')
            ->ability('view', 'View activity log');

        $prefix     = config('kinetix.route_prefix', '_kinetix');
        $middleware = config('kinetix.middleware', ['web', 'auth']);

        if (config('kinetix.teams', false)) {
            $prefix = '{current_team}/'.$prefix;

            if (class_exists(PermissionRegistrar::class)) {
                $middleware[] = 'kinetix.permissions.team';
            }
        }

        Route::middleware($middleware)
            ->prefix("{$prefix}/activity")
            ->group(function () {
                Route::get('/', [ActivityController::class, 'index'])
                    ->name('kinetix.activity.index');
            });
    }

    /**
     * Wire the optional Impersonation module: the `users.impersonate` ability,
     * the `kinetix.impersonation.protect` middleware (deny sensitive routes while
     * impersonating), and the start/leave endpoints.
     */
    protected function registerImpersonation(): void
    {
        $this->app['router']->aliasMiddleware('kinetix.impersonation.protect', DenyWhileImpersonating::class);

        if (! config('kinetix.impersonation.enabled', false)) {
            return;
        }

        app(PermissionRegistry::class)->feature('users')
            ->label('Users')
            ->ability('impersonate', 'Impersonate users');

        $prefix     = config('kinetix.route_prefix', '_kinetix');
        $middleware = config('kinetix.middleware', ['web', 'auth']);

        if (config('kinetix.teams', false)) {
            $prefix = '{current_team}/'.$prefix;

            if (class_exists(PermissionRegistrar::class)) {
                $middleware[] = 'kinetix.permissions.team';
            }
        }

        Route::middleware($middleware)
            ->prefix("{$prefix}/impersonate")
            ->group(function () {
                // `leave` first so it isn't captured by the `{user}` parameter.
                Route::delete('/', [ImpersonationController::class, 'leave'])
                    ->name('kinetix.impersonation.leave');
                Route::post('{user}', [ImpersonationController::class, 'start'])
                    ->name('kinetix.impersonation.start');
            });
    }

    /**
     * Wire the optional Feature Flags module: the `kinetix.feature` middleware
     * (always aliased) and, when enabled, the shared flag map for the frontend.
     */
    protected function registerFeatures(): void
    {
        $this->app['router']->aliasMiddleware('kinetix.feature', EnsureFeature::class);
    }

    /**
     * Wire the optional Spotlight module: the search endpoint (team-aware).
     */
    protected function registerSpotlight(): void
    {
        if (! config('kinetix.spotlight.enabled', false)) {
            return;
        }

        $prefix     = config('kinetix.route_prefix', '_kinetix');
        $middleware = config('kinetix.middleware', ['web', 'auth']);

        if (config('kinetix.teams', false)) {
            $prefix = '{current_team}/'.$prefix;

            if (class_exists(PermissionRegistrar::class)) {
                $middleware[] = 'kinetix.permissions.team';
            }
        }

        Route::middleware($middleware)
            ->prefix("{$prefix}/spotlight")
            ->group(function () {
                Route::get('/', [SpotlightController::class, 'search'])
                    ->name('kinetix.spotlight.search');
            });
    }

    /**
     * Wire the optional Webhooks module: the `webhooks.manage` ability and the
     * customer-facing management endpoints (team-aware).
     */
    protected function registerWebhooks(): void
    {
        if (! config('kinetix.webhooks.enabled', false)) {
            return;
        }

        app(PermissionRegistry::class)->feature('webhooks')
            ->label('Webhooks')
            ->ability('manage', 'Manage webhooks');

        // When delivering through spatie/laravel-webhook-server, bridge its
        // per-attempt events into the Kinetix delivery log.
        if (app(WebhookDispatcher::class)->usesWebhookServer()) {
            $this->app['events']->listen([
                WebhookCallSucceededEvent::class,
                WebhookCallFailedEvent::class,
            ], LogSpatieWebhookCall::class);
        }

        $prefix     = config('kinetix.route_prefix', '_kinetix');
        $middleware = config('kinetix.middleware', ['web', 'auth']);

        if (config('kinetix.teams', false)) {
            $prefix = '{current_team}/'.$prefix;

            if (class_exists(PermissionRegistrar::class)) {
                $middleware[] = 'kinetix.permissions.team';
            }
        }

        Route::middleware($middleware)
            ->prefix("{$prefix}/webhooks")
            ->group(function () {
                Route::get('/', [WebhookController::class, 'index'])->name('kinetix.webhooks.index');
                Route::post('/', [WebhookController::class, 'store'])->name('kinetix.webhooks.store');
                Route::put('{endpoint}', [WebhookController::class, 'update'])->name('kinetix.webhooks.update');
                Route::delete('{endpoint}', [WebhookController::class, 'destroy'])->name('kinetix.webhooks.destroy');
                Route::post('{endpoint}/rotate', [WebhookController::class, 'rotate'])->name('kinetix.webhooks.rotate');
                Route::post('{endpoint}/test', [WebhookController::class, 'test'])->name('kinetix.webhooks.test');
                Route::get('{endpoint}/logs', [WebhookController::class, 'logs'])->name('kinetix.webhooks.logs');
                Route::post('logs/{log}/redeliver', [WebhookController::class, 'redeliver'])->name('kinetix.webhooks.redeliver');
            });
    }

    /**
     * Wire the optional Developer Tokens module: self-service personal access
     * token endpoints (each user manages only their own tokens). Requires the
     * User model to use Laravel\Sanctum\HasApiTokens.
     */
    protected function registerTokens(): void
    {
        if (! config('kinetix.tokens.enabled', false)) {
            return;
        }

        $prefix     = config('kinetix.route_prefix', '_kinetix');
        $middleware = config('kinetix.middleware', ['web', 'auth']);

        if (config('kinetix.teams', false)) {
            $prefix = '{current_team}/'.$prefix;

            if (class_exists(PermissionRegistrar::class)) {
                $middleware[] = 'kinetix.permissions.team';
            }
        }

        Route::middleware($middleware)
            ->prefix("{$prefix}/tokens")
            ->group(function () {
                Route::get('/', [TokenController::class, 'index'])->name('kinetix.tokens.index');
                Route::post('/', [TokenController::class, 'store'])->name('kinetix.tokens.store');
                Route::delete('{token}', [TokenController::class, 'destroy'])->name('kinetix.tokens.destroy');
            });
    }

    /**
     * Wire the optional Onboarding module: self-service first-run checklist
     * endpoints (each user reads/updates only their own progress, team-aware).
     */
    protected function registerOnboarding(): void
    {
        if (! config('kinetix.onboarding.enabled', false)) {
            return;
        }

        $prefix     = config('kinetix.route_prefix', '_kinetix');
        $middleware = config('kinetix.middleware', ['web', 'auth']);

        if (config('kinetix.teams', false)) {
            $prefix = '{current_team}/'.$prefix;

            if (class_exists(PermissionRegistrar::class)) {
                $middleware[] = 'kinetix.permissions.team';
            }
        }

        Route::middleware($middleware)
            ->prefix("{$prefix}/onboarding")
            ->group(function () {
                Route::get('/', [OnboardingController::class, 'index'])->name('kinetix.onboarding.index');
                Route::post('complete', [OnboardingController::class, 'complete'])->name('kinetix.onboarding.complete');
                Route::post('dismiss', [OnboardingController::class, 'dismiss'])->name('kinetix.onboarding.dismiss');
            });
    }

    /**
     * Wire the optional Wizards module: the `kinetix.wizard:<slug>` gating
     * middleware alias (always registered so it can be used on app routes) and,
     * when enabled, the self-service completion endpoints.
     */
    protected function registerWizards(): void
    {
        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('kinetix.wizard', EnsureWizardCompleted::class);

        if (! config('kinetix.wizards.enabled', false)) {
            return;
        }

        $prefix     = config('kinetix.route_prefix', '_kinetix');
        $middleware = config('kinetix.middleware', ['web', 'auth']);

        if (config('kinetix.teams', false)) {
            $prefix = '{current_team}/'.$prefix;

            if (class_exists(PermissionRegistrar::class)) {
                $middleware[] = 'kinetix.permissions.team';
            }
        }

        Route::middleware($middleware)
            ->prefix("{$prefix}/wizards")
            ->group(function () {
                Route::get('{slug}', [WizardController::class, 'status'])->name('kinetix.wizards.status');
                Route::post('{slug}/complete', [WizardController::class, 'complete'])->name('kinetix.wizards.complete');
            });
    }

    /**
     * Wire the optional GDPR module: self-service "export my data" + account
     * deletion endpoints (each user acts only on their own account, team-aware
     * prefix, no admin ability).
     */
    protected function registerGdpr(): void
    {
        if (! config('kinetix.gdpr.enabled', false)) {
            return;
        }

        $prefix     = config('kinetix.route_prefix', '_kinetix');
        $middleware = config('kinetix.middleware', ['web', 'auth']);

        if (config('kinetix.teams', false)) {
            $prefix = '{current_team}/'.$prefix;

            if (class_exists(PermissionRegistrar::class)) {
                $middleware[] = 'kinetix.permissions.team';
            }
        }

        Route::middleware($middleware)
            ->prefix("{$prefix}/gdpr")
            ->group(function () {
                Route::post('export', [GdprController::class, 'export'])->name('kinetix.gdpr.export');
                Route::post('delete', [GdprController::class, 'destroy'])->name('kinetix.gdpr.delete');
            });
    }

    /**
     * Wire the optional Accessibility module: self-service per-user preference
     * endpoints (read/update only your own).
     */
    protected function registerAccessibility(): void
    {
        if (! config('kinetix.accessibility.enabled', false)) {
            return;
        }

        $prefix     = config('kinetix.route_prefix', '_kinetix');
        $middleware = config('kinetix.middleware', ['web', 'auth']);

        if (config('kinetix.teams', false)) {
            $prefix = '{current_team}/'.$prefix;

            if (class_exists(PermissionRegistrar::class)) {
                $middleware[] = 'kinetix.permissions.team';
            }
        }

        Route::middleware($middleware)
            ->prefix("{$prefix}/accessibility")
            ->group(function () {
                Route::get('/', [AccessibilityController::class, 'index'])->name('kinetix.accessibility.index');
                Route::post('/', [AccessibilityController::class, 'update'])->name('kinetix.accessibility.update');
            });
    }

    /**
     * Wire the optional Connected Accounts module: authenticated link/unlink +
     * set-password management, plus an opt-in guest social-login flow. Requires
     * laravel/socialite in the host app.
     */
    protected function registerConnectedAccounts(): void
    {
        if (! config('kinetix.connected_accounts.enabled', false)) {
            return;
        }

        $base       = config('kinetix.route_prefix', '_kinetix');
        $middleware = config('kinetix.middleware', ['web', 'auth']);
        $prefix     = $base;

        if (config('kinetix.teams', false)) {
            $prefix = '{current_team}/'.$base;

            if (class_exists(PermissionRegistrar::class)) {
                $middleware[] = 'kinetix.permissions.team';
            }
        }

        // Guest login/registration via a provider (opt-in, no team prefix —
        // there is no team context before authentication).
        if (config('kinetix.connected_accounts.login_enabled', false)) {
            Route::middleware(['web'])
                ->prefix("{$base}/connected-accounts/login")
                ->group(function () {
                    Route::get('redirect/{provider}', [ConnectedAccountController::class, 'loginRedirect'])->name('kinetix.connected-accounts.login.redirect');
                    Route::get('callback/{provider}', [ConnectedAccountController::class, 'loginCallback'])->name('kinetix.connected-accounts.login.callback');
                });
        }

        // Authenticated link/unlink + set-password management.
        Route::middleware($middleware)
            ->prefix("{$prefix}/connected-accounts")
            ->group(function () {
                Route::get('/', [ConnectedAccountController::class, 'index'])->name('kinetix.connected-accounts.index');
                Route::get('redirect/{provider}', [ConnectedAccountController::class, 'redirect'])->name('kinetix.connected-accounts.redirect');
                Route::get('callback/{provider}', [ConnectedAccountController::class, 'callback'])->name('kinetix.connected-accounts.callback');
                Route::post('password', [ConnectedAccountController::class, 'password'])->name('kinetix.connected-accounts.password');
                Route::delete('{account}', [ConnectedAccountController::class, 'destroy'])->name('kinetix.connected-accounts.destroy');
            });
    }

    /**
     * Wire the optional Browser Sessions module: list the user's active sessions
     * and log out other devices (self-service, reads Laravel's sessions table).
     */
    protected function registerSessions(): void
    {
        if (! config('kinetix.sessions.enabled', false)) {
            return;
        }

        $prefix     = config('kinetix.route_prefix', '_kinetix');
        $middleware = config('kinetix.middleware', ['web', 'auth']);

        if (config('kinetix.teams', false)) {
            $prefix = '{current_team}/'.$prefix;

            if (class_exists(PermissionRegistrar::class)) {
                $middleware[] = 'kinetix.permissions.team';
            }
        }

        Route::middleware($middleware)
            ->prefix("{$prefix}/sessions")
            ->group(function () {
                Route::get('/', [SessionController::class, 'index'])->name('kinetix.sessions.index');
                Route::delete('others', [SessionController::class, 'destroyOthers'])->name('kinetix.sessions.destroy-others');
            });
    }

    /**
     * Share Kinetix config and notifications with Inertia page props.
     */
    protected function shareInertiaData(): void
    {
        Inertia::share('kinetix_config', function () {
            $routePrefix = config('kinetix.route_prefix', '_kinetix');

            if (config('kinetix.teams', false)) {
                $team = request()->route('current_team')
                    ?? (auth()->check() && auth()->user()->currentTeam ? auth()->user()->currentTeam->id : null);

                if ($team) {
                    $routePrefix = "{$team}/{$routePrefix}";
                }
            }

            return [
                'database'     => (bool) config('kinetix.notifications.database', false),
                'route_prefix' => $routePrefix,
                'sound'        => [
                    'enabled' => (bool) config('kinetix.notifications.sound.enabled', true),
                    'path'    => config('kinetix.notifications.sound.path', '/vendor/kinetix/notification.wav'),
                ],
                'broadcasting' => $this->getBroadcastingConfig(),
            ];
        });

        Inertia::share('kinetix_notifications', function () {
            $isDatabase = (bool) config('kinetix.notifications.database', false);
            $limit      = (int) config('kinetix.notifications.limit', 15);

            if ($isDatabase && auth()->check()) {
                return auth()->user()->notifications()
                    ->latest()
                    ->take($limit)
                    ->get()
                    ->map(fn ($n) => array_merge($n->data, [
                        'id'         => $n->id,
                        'read'       => ! is_null($n->read_at),
                        'created_at' => $n->created_at->toIso8601String(),
                    ]))
                    ->toArray();
            }

            return session()->get('kinetix_notifications', []);
        });

        // The authenticated user's resolved permission keys + role names, so the
        // SPA can gate UI (useKinetixCan / <Can>) without the host app having to
        // wire its own HandleInertiaRequests. Empty unless the feature is on.
        Inertia::share('kinetix_permissions', function () {
            $user = auth()->user();

            if (! config('kinetix.permissions.enabled', false) || $user === null) {
                return ['enabled' => false, 'permissions' => [], 'roles' => []];
            }

            return [
                'enabled'     => true,
                'permissions' => method_exists($user, 'getAllPermissions')
                    ? $user->getAllPermissions()->pluck('name')->values()->all()
                    : [],
                'roles' => method_exists($user, 'getRoleNames')
                    ? $user->getRoleNames()->values()->all()
                    : [],
            ];
        });

        // Whether the current session is an impersonation, for <KinetixImpersonationBanner>.
        Inertia::share('kinetix_impersonation', function () {
            $manager = app(ImpersonationManager::class);

            if (! config('kinetix.impersonation.enabled', false) || ! $manager->isImpersonating()) {
                return ['active' => false];
            }

            $user = auth()->user();

            return [
                'active' => true,
                'user'   => [
                    'id'   => $user?->getAuthIdentifier(),
                    'name' => $user instanceof Model ? $user->getAttribute('name') : null,
                ],
            ];
        });

        // Resolved feature flags for the current scope, for useKinetixFeature / <KinetixFeature>.
        Inertia::share('kinetix_features', function () {
            if (! config('kinetix.features.enabled', false)) {
                return [];
            }

            return app(FeatureManager::class)->all();
        });

        // The current user's accessibility preferences (applied by the
        // KinetixAccessibility Vue plugin), or the configured defaults.
        Inertia::share('kinetix_accessibility', function () {
            if (! config('kinetix.accessibility.enabled', false)) {
                return null;
            }

            $user = auth()->user();

            return $user instanceof Model
                ? app(AccessibilityManager::class)->for($user)
                : AccessibilityData::fromArray((array) config('kinetix.accessibility.defaults', []));
        });
    }

    /**
     * Build the broadcasting config to share with the frontend.
     * Only exposes public-safe (VITE_-prefixed equivalent) values.
     *
     * @return array<string, mixed>|null
     */
    protected function getBroadcastingConfig(): ?array
    {
        $echoConfig = config('kinetix.broadcasting.echo');

        if (empty($echoConfig)) {
            return null;
        }

        return $echoConfig;
    }

    /**
     * Register routing for handling notification actions in the database.
     */
    protected function registerNotificationRoutes(): void
    {
        $prefix     = config('kinetix.route_prefix', '_kinetix');
        $middleware = config('kinetix.middleware', ['web', 'auth']);

        if (config('kinetix.teams', false)) {
            $prefix = '{current_team}/'.$prefix;
        }

        Route::middleware($middleware)
            ->prefix("{$prefix}/notifications")
            ->group(function () {
                Route::post('{id}/read', function (Request $request) {
                    // Resolve by name (not positionally): with teams enabled the
                    // prefix adds a leading `{current_team}` param, so a
                    // positional `$id` would receive the team, not the id.
                    $id = (string) $request->route('id');
                    auth()->user()->unreadNotifications()->where('id', $id)->first()?->markAsRead();

                    return response()->json(['status' => 'success']);
                })->name('kinetix.notifications.read');

                Route::post('read-all', function () {
                    auth()->user()->unreadNotifications->markAsRead();

                    return response()->json(['status' => 'success']);
                })->name('kinetix.notifications.read-all');

                Route::delete('clear-all', function () {
                    auth()->user()->notifications()->delete();

                    return response()->json(['status' => 'success']);
                })->name('kinetix.notifications.clear-all');

                Route::delete('{id}', function (Request $request) {
                    // Resolve by name (not positionally): with teams enabled the
                    // prefix adds a leading `{current_team}` param, so a
                    // positional `$id` would receive the team, not the id.
                    $id = (string) $request->route('id');
                    auth()->user()->notifications()->where('id', $id)->delete();

                    return response()->json(['status' => 'success']);
                })->name('kinetix.notifications.delete');
            });
    }

    /**
     * Register routing for handling inline table edits.
     */
    protected function registerTableRoutes(): void
    {
        $prefix     = config('kinetix.route_prefix', '_kinetix');
        $middleware = config('kinetix.middleware', ['web', 'auth']);

        if (config('kinetix.teams', false)) {
            $prefix = '{current_team}/'.$prefix;
        }

        Route::middleware($middleware)
            ->prefix("{$prefix}/tables")
            ->group(function () {
                Route::post('cell-update', function () {
                    $encryptedModel = request('model');
                    $recordId       = request('recordId');
                    $column         = (string) request('column');
                    $value          = request('value');

                    try {
                        $payload = Crypt::decrypt((string) $encryptedModel);
                    } catch (\Exception $e) {
                        return response()->json(['status' => 'error', 'message' => 'Invalid model signature.'], 400);
                    }

                    $modelClass      = is_array($payload) ? ($payload['model'] ?? null) : null;
                    $editableColumns = is_array($payload) ? ($payload['columns'] ?? []) : [];

                    if (! is_string($modelClass) || ! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
                        return response()->json(['status' => 'error', 'message' => 'Invalid model class.'], 400);
                    }

                    // Only columns explicitly declared as editable on the table may be written,
                    // preventing tampering with arbitrary (e.g. privileged) attributes.
                    if (! is_array($editableColumns) || ! in_array($column, $editableColumns, true)) {
                        return response()->json(['status' => 'error', 'message' => 'Column is not editable.'], 403);
                    }

                    $record = $modelClass::find($recordId);

                    if (! $record) {
                        return response()->json(['status' => 'error', 'message' => 'Record not found.'], 404);
                    }

                    $record->{$column} = $value;
                    $record->save();

                    return response()->json(['status' => 'success']);
                })->name('kinetix.tables.cell-update');

                Route::post('reorder', function () {
                    try {
                        $payload = Crypt::decrypt((string) request('model'));
                    } catch (\Exception $e) {
                        return response()->json(['status' => 'error', 'message' => 'Invalid model signature.'], 400);
                    }

                    $modelClass    = is_array($payload) ? ($payload['model'] ?? null) : null;
                    $reorderColumn = is_array($payload) ? ($payload['reorder'] ?? null) : null;

                    if (! is_string($modelClass) || ! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
                        return response()->json(['status' => 'error', 'message' => 'Invalid model class.'], 400);
                    }

                    // The reorder column is baked into the signed token only when
                    // the table opted in via reorderable(); otherwise reject.
                    if (! is_string($reorderColumn) || $reorderColumn === '') {
                        return response()->json(['status' => 'error', 'message' => 'Table is not reorderable.'], 403);
                    }

                    /** @var array<int, mixed> $ids */
                    $ids = (array) request('ids', []);

                    foreach (array_values($ids) as $position => $id) {
                        $modelClass::query()->whereKey($id)->update([$reorderColumn => $position + 1]);
                    }

                    return response()->json(['status' => 'success']);
                })->name('kinetix.tables.reorder');
            });
    }

    /**
     * Register routing for the import preview and dispatch flow.
     */
    protected function registerImportRoutes(): void
    {
        $prefix     = config('kinetix.route_prefix', '_kinetix');
        $middleware = config('kinetix.middleware', ['web', 'auth']);

        if (config('kinetix.teams', false)) {
            $prefix = '{current_team}/'.$prefix;
        }

        Route::middleware($middleware)
            ->prefix("{$prefix}/imports")
            ->group(function () {
                Route::post('upload', [ImportController::class, 'upload'])
                    ->name('kinetix.imports.upload');

                Route::post('preview', [ImportController::class, 'preview'])
                    ->name('kinetix.imports.preview');

                Route::post('start', [ImportController::class, 'start'])
                    ->name('kinetix.imports.start');
            });
    }

    /**
     * Register the file-upload endpoints for the FileUpload form field.
     */
    protected function registerUploadRoutes(): void
    {
        $prefix     = config('kinetix.route_prefix', '_kinetix');
        $middleware = config('kinetix.middleware', ['web', 'auth']);

        if (config('kinetix.teams', false)) {
            $prefix = '{current_team}/'.$prefix;
        }

        Route::middleware($middleware)
            ->prefix("{$prefix}/uploads")
            ->group(function () {
                Route::post('store', [UploadController::class, 'store'])
                    ->name('kinetix.uploads.store');

                Route::post('delete', [UploadController::class, 'delete'])
                    ->name('kinetix.uploads.delete');
            });

        // Remote options for searchable Select fields (token-guarded).
        Route::middleware($middleware)
            ->prefix("{$prefix}/forms")
            ->group(function () {
                Route::post('search', SearchController::class)
                    ->name('kinetix.forms.search');
            });
    }

    /**
     * Register the optional Billing module: the `plan.feature` middleware alias
     * and, when enabled, the bundled billing routes.
     */
    protected function registerBilling(): void
    {
        /** @var Router $router */
        $router = $this->app['router'];
        $router->aliasMiddleware('plan.feature', PlanFeatureMiddleware::class);

        if (config('kinetix.billing.enabled', false) && config('kinetix.billing.auto_routes', false)) {
            BillingRoutes::register();
        }
    }

    /**
     * Register the export download endpoint.
     *
     * Registered without the team prefix so the signed download URL can be built
     * from a queued job that has no team route context. Access is guarded by the
     * signed token plus the configured middleware.
     */
    protected function registerExportRoutes(): void
    {
        $prefix     = config('kinetix.route_prefix', '_kinetix');
        $middleware = config('kinetix.middleware', ['web', 'auth']);

        Route::middleware($middleware)
            ->prefix("{$prefix}/exports")
            ->group(function () {
                Route::post('start', [ExportController::class, 'start'])
                    ->name('kinetix.exports.start');

                Route::get('download', [ExportController::class, 'download'])
                    ->name('kinetix.exports.download');
            });
    }

    /**
     * Validate the application environment constraints.
     */
    protected function validateEnvironment(): void
    {
        // 1. PHP 8.3+ validation
        if (version_compare(PHP_VERSION, '8.3.0', '<')) {
            throw new \RuntimeException('Kinetix requires PHP 8.3 or superior.');
        }

        // 2. Front-end environment validations (only validate when boot is running in web request or testing to avoid breaking setup commands)
        if (! $this->app->runningInConsole() || $this->app->runningUnitTests()) {
            $packageJsonPath = base_path('package.json');

            if (file_exists($packageJsonPath)) {
                $packageJson  = json_decode(file_get_contents($packageJsonPath), true);
                $dependencies = array_merge(
                    $packageJson['dependencies']    ?? [],
                    $packageJson['devDependencies'] ?? []
                );

                // Inertia & Vue 3 validation
                if (! isset($dependencies['vue']) || ! isset($dependencies['@inertiajs/vue3'])) {
                    throw new \RuntimeException('Kinetix requires Vue 3 and Inertia 3 to be installed in your project.');
                }

                // Shadcn / Reka-UI / Radix-Vue check
                $hasShadcn = file_exists(base_path('components.json'))
                    || isset($dependencies['reka-ui'])
                    || isset($dependencies['radix-vue']);

                if (! $hasShadcn) {
                    throw new \RuntimeException('Kinetix requires Shadcn Vue / Reka UI configuration in your project.');
                }
            }
        }
    }
}
