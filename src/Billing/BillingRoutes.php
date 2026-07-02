<?php

declare(strict_types=1);

namespace Happones\Kinetix\Billing;

use Illuminate\Support\Facades\Route;

/**
 * Registers the billing endpoints. Call from a routes file for full control:
 *
 *     \Happones\Kinetix\Billing\BillingRoutes::register();
 *
 * or let the service provider register them automatically when
 * `kinetix.billing.enabled` and `kinetix.billing.auto_routes` are both true.
 */
class BillingRoutes
{
    public static function register(): void
    {
        $prefix = (string) config('kinetix.billing.route_prefix', 'billing');
        if (config('kinetix.billing.teams', false) && config('kinetix.tenancy.subdomain') === null) {
            $prefix = '{team}/'.$prefix;
        }
        $name       = (string) config('kinetix.billing.route_name', 'billing.');
        $middleware = config('kinetix.billing.middleware', ['web', 'auth']);
        $controller = BillingController::class;

        Route::middleware($middleware)
            ->prefix($prefix)
            ->name($name)
            ->group(function () use ($controller): void {
                Route::get('/', [$controller, 'index'])->name('index');
                Route::post('subscribe', [$controller, 'subscribe'])->name('subscribe');
                Route::post('payment-methods', [$controller, 'addPaymentMethod'])->name('payment-methods.add');
                Route::delete('payment-methods/{id}', [$controller, 'removePaymentMethod'])->name('payment-methods.remove');
                Route::get('invoices/{id}/download', [$controller, 'downloadInvoice'])->name('invoices.download');
                Route::post('cancel', [$controller, 'cancel'])->name('cancel');
                Route::post('resume', [$controller, 'resume'])->name('resume');
            });
    }
}
