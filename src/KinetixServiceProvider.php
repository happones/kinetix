<?php

declare(strict_types=1);

namespace Happones\Kinetix;

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
use Happones\Kinetix\Exports\ExportController;
use Happones\Kinetix\Forms\UploadController;
use Happones\Kinetix\Imports\ImportController;
use Happones\Kinetix\Membership\MembershipController;
use Happones\Kinetix\Permissions\Middleware\SetPermissionsTeam;
use Happones\Kinetix\Permissions\PermissionController;
use Happones\Kinetix\Permissions\PermissionRegistry;
use Happones\Kinetix\Settings\KinetixSettings;
use Happones\Kinetix\Settings\SettingsController;
use Happones\Kinetix\Settings\SettingsManager;
use Happones\Kinetix\Settings\SettingsPage;
use Happones\Kinetix\Settings\SettingsRegistry;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;
use Inertia\Inertia;
use Spatie\Permission\PermissionRegistrar;

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
