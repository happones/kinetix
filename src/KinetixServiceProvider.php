<?php

declare(strict_types=1);

namespace Happones\Kinetix;

use Happones\Kinetix\Accessibility\AccessibilityController;
use Happones\Kinetix\Accessibility\AccessibilityManager;
use Happones\Kinetix\Activity\ActivityController;
use Happones\Kinetix\Activity\ActivityLogger;
use Happones\Kinetix\Announcements\AnnouncementController;
use Happones\Kinetix\Announcements\AnnouncementManager;
use Happones\Kinetix\Api\ApiLogController;
use Happones\Kinetix\Api\Middleware\LogApiRequest;
use Happones\Kinetix\Billing\BillingRoutes;
use Happones\Kinetix\Billing\Middleware\PlanFeatureMiddleware;
use Happones\Kinetix\Commands\ActivityPruneCommand;
use Happones\Kinetix\Commands\ApiLogsPruneCommand;
use Happones\Kinetix\Commands\ConfidentialEncryptExistingCommand;
use Happones\Kinetix\Commands\ConfidentialRotateKeyCommand;
use Happones\Kinetix\Commands\DispatchDueReportSchedulesCommand;
use Happones\Kinetix\Commands\InstallCommand;
use Happones\Kinetix\Commands\MakeActionCommand;
use Happones\Kinetix\Commands\MakeBillingCommand;
use Happones\Kinetix\Commands\MakeExporterCommand;
use Happones\Kinetix\Commands\MakeFormCommand;
use Happones\Kinetix\Commands\MakeImporterCommand;
use Happones\Kinetix\Commands\MakeInfolistCommand;
use Happones\Kinetix\Commands\MakeNotificationCommand;
use Happones\Kinetix\Commands\MakeRelationManagerCommand;
use Happones\Kinetix\Commands\MakeReportCommand;
use Happones\Kinetix\Commands\MakeResourceCommand;
use Happones\Kinetix\Commands\MakeSettingsPageCommand;
use Happones\Kinetix\Commands\MakeTableCommand;
use Happones\Kinetix\Commands\PermissionsSyncCommand;
use Happones\Kinetix\Commands\ReportRunsPruneCommand;
use Happones\Kinetix\Commands\SendNotificationCommand;
use Happones\Kinetix\Commands\SendReportsCommand;
use Happones\Kinetix\Commands\UpgradeCommand;
use Happones\Kinetix\Commands\WebhooksPruneCommand;
use Happones\Kinetix\Comments\CommentController;
use Happones\Kinetix\Comments\CommentManager;
use Happones\Kinetix\Comments\CommentRegistry;
use Happones\Kinetix\Confidential\ConfidentialController;
use Happones\Kinetix\Confidential\ConfidentialManager;
use Happones\Kinetix\Confidential\KeyManagers\KeyManager;
use Happones\Kinetix\Confidential\KeyManagers\LocalKeyManager;
use Happones\Kinetix\ConnectedAccounts\ConnectedAccountController;
use Happones\Kinetix\ConnectedAccounts\ConnectedAccountManager;
use Happones\Kinetix\ConnectedAccounts\ConnectedAccountProviderRegistry;
use Happones\Kinetix\Data\AccessibilityData;
use Happones\Kinetix\Exports\ExportController;
use Happones\Kinetix\Features\FeatureManager;
use Happones\Kinetix\Features\Middleware\EnsureFeature;
use Happones\Kinetix\Forms\SearchController;
use Happones\Kinetix\Forms\TableRepeaterController;
use Happones\Kinetix\Forms\UploadController;
use Happones\Kinetix\Gdpr\GdprController;
use Happones\Kinetix\Gdpr\GdprManager;
use Happones\Kinetix\Gdpr\GdprRegistry;
use Happones\Kinetix\Health\HealthController;
use Happones\Kinetix\Health\HealthMetrics;
use Happones\Kinetix\Impersonation\ImpersonationController;
use Happones\Kinetix\Impersonation\ImpersonationManager;
use Happones\Kinetix\Impersonation\Middleware\DenyWhileImpersonating;
use Happones\Kinetix\Imports\ImportController;
use Happones\Kinetix\Locale\LocaleController;
use Happones\Kinetix\Locale\LocaleManager;
use Happones\Kinetix\Locale\Middleware\SetKinetixLocale;
use Happones\Kinetix\Mail\MailTemplateController;
use Happones\Kinetix\Media\MediaManager;
use Happones\Kinetix\Membership\MembershipController;
use Happones\Kinetix\NotificationPreferences\NotificationPreferenceController;
use Happones\Kinetix\NotificationPreferences\NotificationPreferenceManager;
use Happones\Kinetix\NotificationPreferences\NotificationTypeRegistry;
use Happones\Kinetix\Onboarding\OnboardingController;
use Happones\Kinetix\Onboarding\OnboardingManager;
use Happones\Kinetix\Onboarding\OnboardingStepRegistry;
use Happones\Kinetix\Pdf\PdfTemplateController;
use Happones\Kinetix\Pdf\PdfTemplateRegistry;
use Happones\Kinetix\Permissions\Middleware\SetPermissionsTeam;
use Happones\Kinetix\Permissions\PermissionController;
use Happones\Kinetix\Permissions\PermissionRegistry;
use Happones\Kinetix\Presence\PresenceManager;
use Happones\Kinetix\Queue\QueueController;
use Happones\Kinetix\Queue\QueueMetrics;
use Happones\Kinetix\Reports\ReportRegistry;
use Happones\Kinetix\ReportsCenter\ReportRegistry as ReportsCenterRegistry;
use Happones\Kinetix\ReportsCenter\ReportRunController;
use Happones\Kinetix\ReportsCenter\ReportRunDispatcher;
use Happones\Kinetix\ReportsCenter\ReportScheduleController;
use Happones\Kinetix\ReportsCenter\ReportTypeController;
use Happones\Kinetix\SavedViews\SavedViewController;
use Happones\Kinetix\SavedViews\SavedViewManager;
use Happones\Kinetix\Sessions\BrowserSessionManager;
use Happones\Kinetix\Sessions\SessionController;
use Happones\Kinetix\Settings\KinetixSettings;
use Happones\Kinetix\Settings\SettingsController;
use Happones\Kinetix\Settings\SettingsManager;
use Happones\Kinetix\Settings\SettingsPage;
use Happones\Kinetix\Settings\SettingsRegistry;
use Happones\Kinetix\Spotlight\SpotlightController;
use Happones\Kinetix\Spotlight\SpotlightRegistry;
use Happones\Kinetix\Support\KinetixTeams;
use Happones\Kinetix\Tags\TagController;
use Happones\Kinetix\Tags\TagManager;
use Happones\Kinetix\Tags\TagRegistry;
use Happones\Kinetix\Teams\TeamSwitcherManager;
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
use Illuminate\Support\Facades\Broadcast;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
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
        $this->app->singleton(PdfTemplateRegistry::class);

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

        // The comments allowlist registry + manager.
        $this->app->singleton(CommentRegistry::class);
        $this->app->singleton(CommentManager::class);

        // The tags allowlist registry + manager.
        $this->app->singleton(TagRegistry::class);
        $this->app->singleton(TagManager::class);

        // The notification-type registry (seeded from config) + preference manager.
        $this->app->singleton(NotificationTypeRegistry::class, function (): NotificationTypeRegistry {
            $registry = new NotificationTypeRegistry;
            $registry->register((array) config('kinetix.notification_preferences.types', []));

            return $registry;
        });
        $this->app->singleton(NotificationPreferenceManager::class);

        // The saved-views manager (per-user table presets).
        $this->app->singleton(SavedViewManager::class);

        // The announcements manager (feed + per-user unread tracking).
        $this->app->singleton(AnnouncementManager::class);

        // The locale manager (language switcher: resolve/apply/persist).
        $this->app->singleton(LocaleManager::class);

        // The team-switcher manager (resolves teams + switch URLs by convention).
        $this->app->singleton(TeamSwitcherManager::class);

        // The presence manager (online indicators over a presence channel).
        $this->app->singleton(PresenceManager::class);

        // The queue-metrics reader (Horizon-aware, with a driver fallback).
        $this->app->singleton(QueueMetrics::class);

        // The health-metrics reader (spatie/laravel-health, guarded).
        $this->app->singleton(HealthMetrics::class);

        // The media manager (spatie/laravel-medialibrary bridge, guarded).
        $this->app->singleton(MediaManager::class);

        // The scheduled-reports registry (definitions registered app-side).
        $this->app->singleton(ReportRegistry::class);

        // The Reports Center registry: manual registration + directory
        // auto-discovery of `Report` subclasses (see registerReportsCenter()).
        $this->app->singleton(ReportsCenterRegistry::class, function (): ReportsCenterRegistry {
            $registry = new ReportsCenterRegistry;
            $registry->discover(
                (string) config('kinetix.reports_center.discover_path', app_path('Kinetix/Reports')),
                (string) config('kinetix.reports_center.discover_namespace', 'App\\Kinetix\\Reports'),
            );

            return $registry;
        });
        $this->app->singleton(ReportRunDispatcher::class);

        // Confidential fields: 'local' (zero-dependency, wraps keys via the
        // app's own APP_KEY) or a host-supplied class implementing KeyManager
        // (e.g. their own AWS/GCP KMS or Vault Transit binding).
        $this->app->bind(KeyManager::class, function () {
            $driver = (string) config('kinetix.confidential.key_manager', 'local');

            return $driver === 'local' ? new LocalKeyManager : $this->app->make($driver);
        });
        $this->app->singleton(ConfidentialManager::class);
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
                SendReportsCommand::class,
                PermissionsSyncCommand::class,
                ApiLogsPruneCommand::class,
                MakeReportCommand::class,
                DispatchDueReportSchedulesCommand::class,
                ReportRunsPruneCommand::class,
                ConfidentialRotateKeyCommand::class,
                ConfidentialEncryptExistingCommand::class,
                UpgradeCommand::class,
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

            // Publish translations directly into Laravel lang directory so
            // generators can pick them up — limited to the locales selected in
            // `kinetix.translations.locales` (null/empty = all shipped).
            $this->publishes($this->translationPublishMap(), 'kinetix-translations');

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

            // Publish the optional Comments module's migration.
            $this->publishes([
                __DIR__.'/../database/migrations/2026_01_01_000010_create_kinetix_comments_table.php' => database_path('migrations/2026_01_01_000010_create_kinetix_comments_table.php'),
            ], 'kinetix-comments-migrations');

            // Publish the optional Tags module's migration.
            $this->publishes([
                __DIR__.'/../database/migrations/2026_01_01_000011_create_kinetix_tags_table.php' => database_path('migrations/2026_01_01_000011_create_kinetix_tags_table.php'),
            ], 'kinetix-tags-migrations');

            // Publish the optional Notification Preferences module's migration.
            $this->publishes([
                __DIR__.'/../database/migrations/2026_01_01_000012_create_kinetix_notification_preferences_table.php' => database_path('migrations/2026_01_01_000012_create_kinetix_notification_preferences_table.php'),
            ], 'kinetix-notification-preferences-migrations');

            // Publish the optional Saved Views module's migration.
            $this->publishes([
                __DIR__.'/../database/migrations/2026_01_01_000013_create_kinetix_saved_views_table.php' => database_path('migrations/2026_01_01_000013_create_kinetix_saved_views_table.php'),
            ], 'kinetix-saved-views-migrations');

            // Publish the optional Announcements module's migration.
            $this->publishes([
                __DIR__.'/../database/migrations/2026_01_01_000014_create_kinetix_announcements_table.php' => database_path('migrations/2026_01_01_000014_create_kinetix_announcements_table.php'),
            ], 'kinetix-announcements-migrations');

            // Publish the optional locale column migration (language switcher).
            $this->publishes([
                __DIR__.'/../database/migrations/2026_01_01_000015_add_locale_to_users_table.php' => database_path('migrations/2026_01_01_000015_add_locale_to_users_table.php'),
            ], 'kinetix-locale-migrations');

            // Publish the optional Mail Templates module's migration.
            $this->publishes([
                __DIR__.'/../database/migrations/2026_01_01_000016_create_kinetix_mail_templates_table.php' => database_path('migrations/2026_01_01_000016_create_kinetix_mail_templates_table.php'),
            ], 'kinetix-mail-templates-migrations');

            // Publish the hybrid teams migration for spatie's permission tables
            // (nullable team key outside the PK → global + team-scoped roles).
            $this->publishes([
                __DIR__.'/../database/migrations/2026_01_01_000017_add_kinetix_team_fields_to_permission_tables.php' => database_path('migrations/2026_01_01_000017_add_kinetix_team_fields_to_permission_tables.php'),
            ], 'kinetix-permission-team-migrations');

            // Publish the optional API request logs migration.
            $this->publishes([
                __DIR__.'/../database/migrations/2026_01_01_000018_create_kinetix_api_logs_table.php' => database_path('migrations/2026_01_01_000018_create_kinetix_api_logs_table.php'),
            ], 'kinetix-api-logs-migrations');

            // Publish the optional PDF Templates settings migration.
            $this->publishes([
                __DIR__.'/../database/migrations/2026_01_01_000019_create_kinetix_pdf_templates_table.php' => database_path('migrations/2026_01_01_000019_create_kinetix_pdf_templates_table.php'),
            ], 'kinetix-pdf-migrations');

            // Publish the optional Reports Center module's migrations.
            $this->publishes([
                __DIR__.'/../database/migrations/2026_01_01_000020_create_kinetix_report_schedules_table.php' => database_path('migrations/2026_01_01_000020_create_kinetix_report_schedules_table.php'),
                __DIR__.'/../database/migrations/2026_01_01_000021_create_kinetix_report_runs_table.php'      => database_path('migrations/2026_01_01_000021_create_kinetix_report_runs_table.php'),
            ], 'kinetix-reports-center-migrations');

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

        // Register the optional API request logs module (middleware + viewer feed)
        $this->registerApiLogs();

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

        // Register the optional Comments module (polymorphic threaded comments)
        $this->registerComments();

        // Register the optional Tags module (polymorphic tagging)
        $this->registerTags();

        // Register the optional Notification Preferences module (opt-in matrix)
        $this->registerNotificationPreferences();

        // Register the optional Saved Views module (per-user table presets)
        $this->registerSavedViews();

        // Register the optional Announcements module ("what's new" feed)
        $this->registerAnnouncements();

        // Register the optional Locale module (language switcher)
        $this->registerLocale();

        // Register the optional Presence module (online indicators)
        $this->registerPresence();

        // Register the optional Queue metrics module (Horizon widget)
        $this->registerQueue();

        // Register the optional Mail Templates module (editable email templates)
        $this->registerMailTemplates();

        // Register the optional PDF Templates module (configurable documents)
        $this->registerPdfTemplates();

        // Register the optional Health metrics module (status widget)
        $this->registerHealth();

        // Register the optional Reports Center module (queued, tracked reports)
        $this->registerReportsCenter();

        // Register the optional Confidential Fields module (encrypted, masked attributes)
        $this->registerConfidential();

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

        // Surface the classic silent misconfiguration: Kinetix team scoping on
        // but spatie's own `permission.teams` off — the middleware would set a
        // team id that every hasRole()/can() call silently ignores.
        if (KinetixTeams::enabledFor('permissions')
            && class_exists(PermissionRegistrar::class)
            && ! config('permission.teams', false)) {
            Log::warning(
                'Kinetix: `kinetix.permissions.teams` is true but spatie\'s `permission.teams` is false — '
                .'roles/permissions will NOT be team-scoped. Set `\'teams\' => true` in config/permission.php '
                .'and make the tables teams-ready (vendor:publish --tag=kinetix-permission-team-migrations).'
            );
        }

        // The built-in feature that guards the role-management endpoints/UI.
        app(PermissionRegistry::class)->feature('roles')->label('Roles & Permissions')->ability('manage', 'Manage roles');

        // A super-admin role bypasses every gate check.
        $superAdmin = (string) config('kinetix.permissions.super_admin_role', 'super-admin');

        if ($superAdmin !== '') {
            Gate::before(function ($user, string $ability) use ($superAdmin): ?bool {
                return $this->isSuperAdmin($user, $superAdmin) ? true : null;
            });
        }

        $this->registerPermissionRoutes();
    }

    /**
     * Whether the user holds the super-admin role — in the current team context
     * or, when spatie team scoping is active, as a global assignment (team
     * NULL, so a platform super-admin keeps access inside every team).
     */
    protected function isSuperAdmin(mixed $user, string $role): bool
    {
        if (! method_exists($user, 'hasRole')) {
            return false;
        }

        if ($user->hasRole($role)) {
            return true;
        }

        // With spatie teams on, hasRole() above was scoped to the current team;
        // re-check with a NULL team id to honor a teamless assignment.
        if (! $user instanceof Model || ! config('permission.teams', false) || ! class_exists(PermissionRegistrar::class)) {
            return false;
        }

        $registrar = $this->app->make(PermissionRegistrar::class);
        $current   = $registrar->getPermissionsTeamId();

        if ($current === null) {
            return false; // Already teamless — the first check covered it.
        }

        try {
            $registrar->setPermissionsTeamId(null);
            $user->unsetRelation('roles');

            return $user->hasRole($role);
        } finally {
            $registrar->setPermissionsTeamId($current);
            $user->unsetRelation('roles');
        }
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
                Route::get('logs', [WebhookController::class, 'allLogs'])->name('kinetix.webhooks.all-logs');
                Route::get('{endpoint}/logs', [WebhookController::class, 'logs'])->name('kinetix.webhooks.logs');
                Route::post('logs/{log}/redeliver', [WebhookController::class, 'redeliver'])->name('kinetix.webhooks.redeliver');
            });
    }

    /**
     * Build the per-locale publish map for the `kinetix-translations` tag,
     * honoring the `kinetix.translations.locales` selection (string
     * "en,es" from env, or an array in the published config).
     *
     * @return array<string, string>
     */
    protected function translationPublishMap(): array
    {
        $shipped = array_map('basename', glob(__DIR__.'/../resources/lang/*', GLOB_ONLYDIR) ?: []);

        $selected = config('kinetix.translations.locales');

        if (is_string($selected)) {
            $selected = array_filter(array_map('trim', explode(',', $selected)));
        }

        $selected = array_intersect((array) $selected, $shipped);

        if ($selected === []) {
            $selected = $shipped;
        }

        $map = [];

        foreach ($selected as $locale) {
            $map[__DIR__."/../resources/lang/{$locale}"] = lang_path($locale);
        }

        return $map;
    }

    /**
     * Wire the optional PDF Templates module: registry + the configurator
     * endpoints (descriptor, settings, live preview, download), gated by
     * `viewKinetixPdf` (local-only unless the host defines the gate).
     */
    protected function registerPdfTemplates(): void
    {
        if (! config('kinetix.pdf.enabled', false)) {
            return;
        }

        if (! Gate::has('viewKinetixPdf')) {
            Gate::define('viewKinetixPdf', fn ($user = null): bool => $this->app->environment('local'));
        }

        $prefix     = config('kinetix.route_prefix', '_kinetix');
        $middleware = config('kinetix.middleware', ['web', 'auth']);

        if (config('kinetix.teams', false)) {
            $prefix       = '{current_team}/'.$prefix;
            $middleware[] = 'kinetix.permissions.team';
        }

        Route::middleware($middleware)
            ->prefix("{$prefix}/pdf-templates")
            ->group(function (): void {
                Route::get('/', [PdfTemplateController::class, 'index'])->name('kinetix.pdf-templates.index');
                Route::get('{template}', [PdfTemplateController::class, 'show'])->name('kinetix.pdf-templates.show');
                Route::patch('{template}', [PdfTemplateController::class, 'update'])->name('kinetix.pdf-templates.update');
                Route::get('{template}/preview', [PdfTemplateController::class, 'preview'])->name('kinetix.pdf-templates.preview');
                Route::get('{template}/download', [PdfTemplateController::class, 'download'])->name('kinetix.pdf-templates.download');
            });
    }

    /**
     * Wire the optional API request logs module: a terminable middleware the
     * host attaches to its API group (`kinetix.api-log`) plus the read feed
     * behind the integration-logs viewer, gated by `viewKinetixApiLogs`
     * (local-only unless the host defines the gate).
     */
    protected function registerApiLogs(): void
    {
        // The alias is always available; the middleware no-ops while disabled.
        $this->app['router']->aliasMiddleware('kinetix.api-log', LogApiRequest::class);

        if (! config('kinetix.api_logs.enabled', false)) {
            return;
        }

        if (! Gate::has('viewKinetixApiLogs')) {
            Gate::define('viewKinetixApiLogs', fn ($user = null): bool => $this->app->environment('local'));
        }

        $prefix     = config('kinetix.route_prefix', '_kinetix');
        $middleware = config('kinetix.middleware', ['web', 'auth']);

        if (config('kinetix.teams', false)) {
            $prefix       = '{current_team}/'.$prefix;
            $middleware[] = 'kinetix.permissions.team';
        }

        Route::middleware($middleware)
            ->prefix($prefix)
            ->group(function (): void {
                Route::get('api-logs', [ApiLogController::class, 'index'])->name('kinetix.api-logs.index');
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
     * Wire the optional Comments module: polymorphic, threaded comments on any
     * allowlisted model (self-service — each user edits/deletes only their own).
     */
    protected function registerComments(): void
    {
        if (! config('kinetix.comments.enabled', false)) {
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
            ->prefix("{$prefix}/comments")
            ->group(function () {
                Route::get('/', [CommentController::class, 'index'])->name('kinetix.comments.index');
                Route::post('/', [CommentController::class, 'store'])->name('kinetix.comments.store');
                Route::put('{comment}', [CommentController::class, 'update'])->name('kinetix.comments.update');
                Route::delete('{comment}', [CommentController::class, 'destroy'])->name('kinetix.comments.destroy');
            });
    }

    /**
     * Wire the optional Tags module: polymorphic tagging endpoints (list, suggest,
     * sync) for allowlisted HasKinetixTags models.
     */
    protected function registerTags(): void
    {
        if (! config('kinetix.tags.enabled', false)) {
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
            ->prefix("{$prefix}/tags")
            ->group(function () {
                Route::get('/', [TagController::class, 'index'])->name('kinetix.tags.index');
                Route::get('suggest', [TagController::class, 'suggest'])->name('kinetix.tags.suggest');
                Route::post('sync', [TagController::class, 'sync'])->name('kinetix.tags.sync');
            });
    }

    /**
     * Wire the optional Notification Preferences module: a self-service per-user
     * opt-in matrix of notification types × delivery channels.
     */
    protected function registerNotificationPreferences(): void
    {
        if (! config('kinetix.notification_preferences.enabled', false)) {
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
            ->prefix("{$prefix}/notification-preferences")
            ->group(function () {
                Route::get('/', [NotificationPreferenceController::class, 'index'])->name('kinetix.notification-preferences.index');
                Route::post('/', [NotificationPreferenceController::class, 'update'])->name('kinetix.notification-preferences.update');
            });
    }

    /**
     * Wire the optional Saved Views module: self-service per-user table presets
     * (search/filters/sort/columns) scoped to a view key.
     */
    protected function registerSavedViews(): void
    {
        if (! config('kinetix.saved_views.enabled', false)) {
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
            ->prefix("{$prefix}/saved-views")
            ->group(function () {
                Route::get('/', [SavedViewController::class, 'index'])->name('kinetix.saved-views.index');
                Route::post('/', [SavedViewController::class, 'store'])->name('kinetix.saved-views.store');
                Route::put('{view}', [SavedViewController::class, 'update'])->name('kinetix.saved-views.update');
                Route::delete('{view}', [SavedViewController::class, 'destroy'])->name('kinetix.saved-views.destroy');
                Route::post('{view}/default', [SavedViewController::class, 'makeDefault'])->name('kinetix.saved-views.default');
            });
    }

    /**
     * Wire the optional Announcements module: a self-service "what's new" feed
     * with a per-user unread badge.
     */
    protected function registerAnnouncements(): void
    {
        if (! config('kinetix.announcements.enabled', false)) {
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
            ->prefix("{$prefix}/announcements")
            ->group(function () {
                Route::get('/', [AnnouncementController::class, 'index'])->name('kinetix.announcements.index');
                Route::post('seen', [AnnouncementController::class, 'seen'])->name('kinetix.announcements.seen');
            });
    }

    /**
     * Wire the optional Locale module: a self-service language switcher. The
     * `kinetix.locale` middleware (apply persisted locale) is always aliased so
     * apps can add it to their web group; the switch endpoint is auth-optional
     * so it works on the login screen too.
     */
    protected function registerLocale(): void
    {
        $this->app['router']->aliasMiddleware('kinetix.locale', SetKinetixLocale::class);

        if (! config('kinetix.locale.enabled', false)) {
            return;
        }

        $prefix     = config('kinetix.route_prefix', '_kinetix');
        $middleware = ['web'];

        if (config('kinetix.teams', false)) {
            $prefix = '{current_team}/'.$prefix;

            if (class_exists(PermissionRegistrar::class)) {
                $middleware[] = 'kinetix.permissions.team';
            }
        }

        Route::middleware($middleware)
            ->prefix($prefix)
            ->group(function () {
                Route::post('locale', [LocaleController::class, 'update'])->name('kinetix.locale.update');
            });
    }

    /**
     * Wire the optional Presence module: register the presence channel
     * authorization (team-aware) so the frontend can show who's online. The
     * channel returns each authenticated member's id/name/avatar.
     */
    protected function registerPresence(): void
    {
        if (! config('kinetix.presence.enabled', false) || ! class_exists(Broadcast::class)) {
            return;
        }

        $manager = app(PresenceManager::class);

        Broadcast::channel(
            $manager->channelPattern(),
            fn (Model $user) => $manager->memberData($user),
        );
    }

    /**
     * Wire the optional Queue-metrics module: a read-only snapshot endpoint for
     * the <KinetixQueueStats> widget, gated by the `viewKinetixQueue` ability
     * (defaults to allow only in `local` — override the gate for production).
     */
    protected function registerQueue(): void
    {
        if (! config('kinetix.queue.enabled', false)) {
            return;
        }

        if (! Gate::has('viewKinetixQueue')) {
            Gate::define('viewKinetixQueue', fn ($user = null): bool => $this->app->environment('local'));
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
            ->prefix("{$prefix}/queue")
            ->group(function () {
                Route::get('/', [QueueController::class, 'index'])->name('kinetix.queue.index');
                Route::post('retry', [QueueController::class, 'retry'])->name('kinetix.queue.retry');
                Route::delete('failed', [QueueController::class, 'forget'])->name('kinetix.queue.forget');
            });
    }

    /**
     * Wire the optional Mail Templates module: self-service CRUD + preview/test
     * for editable email templates, gated by the `viewKinetixMail` ability
     * (defaults to allow only in `local`).
     */
    protected function registerMailTemplates(): void
    {
        if (! config('kinetix.mail_templates.enabled', false)) {
            return;
        }

        if (! Gate::has('viewKinetixMail')) {
            Gate::define('viewKinetixMail', fn ($user = null): bool => $this->app->environment('local'));
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
            ->prefix("{$prefix}/mail-templates")
            ->group(function () {
                Route::get('/', [MailTemplateController::class, 'index'])->name('kinetix.mail-templates.index');
                Route::post('/', [MailTemplateController::class, 'store'])->name('kinetix.mail-templates.store');
                Route::post('preview', [MailTemplateController::class, 'preview'])->name('kinetix.mail-templates.preview');
                Route::put('{template}', [MailTemplateController::class, 'update'])->name('kinetix.mail-templates.update');
                Route::delete('{template}', [MailTemplateController::class, 'destroy'])->name('kinetix.mail-templates.destroy');
                Route::post('{template}/test', [MailTemplateController::class, 'test'])->name('kinetix.mail-templates.test');
            });
    }

    /**
     * Wire the optional Health module: a read-only snapshot endpoint for the
     * <KinetixHealthStatus> widget (spatie/laravel-health). Gated by the
     * `viewKinetixHealth` ability (defaults to allow only in `local`).
     */
    protected function registerHealth(): void
    {
        if (! config('kinetix.health.enabled', false)) {
            return;
        }

        if (! Gate::has('viewKinetixHealth')) {
            Gate::define('viewKinetixHealth', fn ($user = null): bool => $this->app->environment('local'));
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
            ->prefix("{$prefix}/health")
            ->group(function () {
                Route::get('/', [HealthController::class, 'index'])->name('kinetix.health.index');
            });
    }

    /**
     * Wire the optional Reports Center module: large-dataset, queued,
     * DB-tracked report generation (progress, cancellation, retry, one-off
     * and recurring scheduling). Gated by the `viewKinetixReportsCenter`
     * ability (defaults to allow only in `local`). Distinct from the
     * lightweight, email-only `reports`/`ScheduledReport` system.
     */
    protected function registerReportsCenter(): void
    {
        if (! config('kinetix.reports_center.enabled', false)) {
            return;
        }

        if (! Gate::has('viewKinetixReportsCenter')) {
            Gate::define('viewKinetixReportsCenter', fn ($user = null): bool => $this->app->environment('local'));
        }

        $prefix     = config('kinetix.route_prefix', '_kinetix');
        $middleware = config('kinetix.middleware', ['web', 'auth']);

        if (config('kinetix.teams', false)) {
            $prefix = '{current_team}/'.$prefix;

            if (class_exists(PermissionRegistrar::class)) {
                $middleware[] = 'kinetix.permissions.team';
            }
        }

        Route::middleware($middleware)->prefix("{$prefix}")->group(function () {
            Route::get('report-types', [ReportTypeController::class, 'index'])->name('kinetix.report-types.index');

            Route::get('report-runs', [ReportRunController::class, 'index'])->name('kinetix.report-runs.index');
            Route::post('report-runs/launch', [ReportRunController::class, 'launch'])->name('kinetix.report-runs.launch');
            Route::post('report-runs/{run}/cancel', [ReportRunController::class, 'cancel'])->name('kinetix.report-runs.cancel');
            Route::post('report-runs/{run}/retry', [ReportRunController::class, 'retry'])->name('kinetix.report-runs.retry');

            Route::get('report-schedules', [ReportScheduleController::class, 'index'])->name('kinetix.report-schedules.index');
            Route::post('report-schedules', [ReportScheduleController::class, 'store'])->name('kinetix.report-schedules.store');
            Route::put('report-schedules/{schedule}', [ReportScheduleController::class, 'update'])->name('kinetix.report-schedules.update');
            Route::delete('report-schedules/{schedule}', [ReportScheduleController::class, 'destroy'])->name('kinetix.report-schedules.destroy');
            Route::post('report-schedules/{schedule}/run-now', [ReportScheduleController::class, 'runNow'])->name('kinetix.report-schedules.run-now');
        });

        // Registered WITHOUT the team prefix, same reasoning as the existing
        // export/GDPR download routes: also reached from a queued job's
        // notification link, which has no team route context at build time.
        Route::middleware($middleware)
            ->prefix((string) config('kinetix.route_prefix', '_kinetix'))
            ->group(function () {
                Route::get('report-runs/{run}/download', [ReportRunController::class, 'download'])
                    ->name('kinetix.report-runs.download');
            });
    }

    /**
     * Register the optional Confidential Fields module: the reveal-gate
     * unlock/lock endpoints and their gate.
     */
    protected function registerConfidential(): void
    {
        if (! config('kinetix.confidential.enabled', false)) {
            return;
        }

        if (! Gate::has('revealKinetixConfidential')) {
            Gate::define('revealKinetixConfidential', fn ($user = null): bool => $this->app->environment('local'));
        }

        $prefix     = config('kinetix.route_prefix', '_kinetix');
        $middleware = config('kinetix.middleware', ['web', 'auth']);

        if (config('kinetix.teams', false)) {
            $prefix = '{current_team}/'.$prefix;

            if (class_exists(PermissionRegistrar::class)) {
                $middleware[] = 'kinetix.permissions.team';
            }
        }

        Route::middleware($middleware)->prefix("{$prefix}")->group(function () {
            Route::post('confidential/unlock', [ConfidentialController::class, 'unlock'])->name('kinetix.confidential.unlock');
            Route::post('confidential/lock', [ConfidentialController::class, 'lock'])->name('kinetix.confidential.lock');
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
                // URL segment → the team's ROUTE key (slug/uuid-aware).
                $team = request()->route('current_team')
                    ?? (auth()->check() && auth()->user()->currentTeam ? auth()->user()->currentTeam->getRouteKey() : null);

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

        // Cookie consent bar config, for <KinetixCookieConsent>. Whether the
        // visitor has already responded is resolved client-side (a plain
        // browser cookie, no server round-trip) — this only ships the
        // configurable bits.
        Inertia::share('kinetix_cookie_consent', function () {
            if (! config('kinetix.cookie_consent.enabled', false)) {
                return ['enabled' => false];
            }

            return [
                'enabled'    => true,
                'cookieName' => config('kinetix.cookie_consent.cookie_name', 'kinetix_cookie_consent'),
                'expiryDays' => (int) config('kinetix.cookie_consent.expiry_days', 365),
                'position'   => config('kinetix.cookie_consent.position', 'bottom'),
                'policyUrl'  => config('kinetix.cookie_consent.policy_url'),
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

        // Supported locales + the active one, for <KinetixLanguageSwitcher>.
        Inertia::share('kinetix_locale', function () {
            if (! config('kinetix.locale.enabled', false)) {
                return ['enabled' => false, 'current' => null, 'locales' => []];
            }

            $manager = app(LocaleManager::class);

            return [
                'enabled' => true,
                'current' => $manager->current(),
                'locales' => $manager->options(),
            ];
        });

        // The user's teams + switch URLs, for <KinetixTeamSwitcher>.
        Inertia::share('kinetix_teams', fn () => app(TeamSwitcherManager::class)->payload());

        // The (team-resolved) presence channel, for <KinetixOnlineUsers>.
        Inertia::share('kinetix_presence', fn () => app(PresenceManager::class)->state());

        // Queue widget config (enabled + poll interval), for <KinetixQueueStats>.
        Inertia::share('kinetix_queue', fn () => [
            'enabled' => (bool) config('kinetix.queue.enabled', false),
            'poll'    => (int) config('kinetix.queue.poll', 5000),
        ]);

        // Health widget config (enabled + poll interval), for <KinetixHealthStatus>.
        Inertia::share('kinetix_health', fn () => [
            'enabled' => (bool) config('kinetix.health.enabled', false),
            'poll'    => (int) config('kinetix.health.poll', 30000),
        ]);

        // Reports Center config (enabled + poll interval), for the launcher /
        // runs table / schedules list components.
        Inertia::share('kinetix_reports_center', fn () => [
            'enabled' => (bool) config('kinetix.reports_center.enabled', false),
            'poll'    => (int) config('kinetix.reports_center.poll', 5000),
        ]);

        // Confidential fields: reveal-gate state for the header unlock widget.
        Inertia::share('kinetix_confidential', function () {
            $unlockedUntil = config('kinetix.confidential.enabled', false)
                ? $this->app->make(ConfidentialManager::class)->unlockedUntil()
                : null;

            return [
                'enabled'       => (bool) config('kinetix.confidential.enabled', false),
                'ttlMinutes'    => (int) config('kinetix.confidential.reveal_ttl_minutes', 5),
                'unlockedUntil' => $unlockedUntil?->toIso8601String(),
            ];
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

                // Kanban card move: set a record's status column to a target
                // status, guarded by the board's signed descriptor (statuses,
                // move scope) and the host's policy.
                Route::post('kanban-move', function () {
                    try {
                        $payload = Crypt::decrypt((string) request('model'));
                    } catch (\Exception $e) {
                        return response()->json(['status' => 'error', 'message' => 'Invalid model signature.'], 400);
                    }

                    $modelClass   = is_array($payload) ? ($payload['model'] ?? null) : null;
                    $statusColumn = is_array($payload) ? ($payload['statusColumn'] ?? null) : null;
                    $statuses     = is_array($payload) ? ($payload['statuses'] ?? []) : [];
                    $moveAbility  = is_array($payload) ? ($payload['moveAbility'] ?? null) : null;
                    $moveScope    = is_array($payload) ? ($payload['moveScope'] ?? []) : [];
                    $status       = (string) request('status');

                    if (! is_string($modelClass) || ! class_exists($modelClass) || ! is_subclass_of($modelClass, Model::class)) {
                        return response()->json(['status' => 'error', 'message' => 'Invalid model class.'], 400);
                    }

                    if (! is_string($statusColumn) || ! is_array($statuses) || ! in_array($status, $statuses, true)) {
                        return response()->json(['status' => 'error', 'message' => 'Invalid status.'], 403);
                    }

                    // The board's moveScope() constraints bound the lookup — a
                    // record outside them (e.g. another tenant's) is a 404.
                    $query = $modelClass::query();

                    if (is_array($moveScope)) {
                        foreach ($moveScope as $column => $value) {
                            $query->where((string) $column, $value);
                        }
                    }

                    $record = $query->find(request('recordId'));

                    if (! $record) {
                        return response()->json(['status' => 'error', 'message' => 'Record not found.'], 404);
                    }

                    // Authorize via the host's policy: the explicit ability from
                    // authorizeMove(), or `update` whenever a policy exists.
                    $ability = is_string($moveAbility)
                        ? $moveAbility
                        : (Gate::getPolicyFor($modelClass) !== null ? 'update' : null);

                    if ($ability !== null && Gate::forUser(request()->user())->denies($ability, $record)) {
                        return response()->json(['status' => 'error', 'message' => 'Forbidden.'], 403);
                    }

                    $record->{$statusColumn} = $status;
                    $record->save();

                    return response()->json(['status' => 'success']);
                })->name('kinetix.tables.kanban-move');

                // TableRepeater autosave: create/update/delete a single row on the
                // bound relation, guarded by the field's signed descriptor.
                Route::post('table-repeater', [TableRepeaterController::class, 'store'])->name('kinetix.table-repeater.store');
                Route::put('table-repeater', [TableRepeaterController::class, 'update'])->name('kinetix.table-repeater.update');
                Route::delete('table-repeater', [TableRepeaterController::class, 'destroy'])->name('kinetix.table-repeater.destroy');
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
                Route::get('template', [ImportController::class, 'template'])
                    ->name('kinetix.imports.template');

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
