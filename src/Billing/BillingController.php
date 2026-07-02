<?php

declare(strict_types=1);

namespace Happones\Kinetix\Billing;

use Happones\Kinetix\Data\PlanData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/**
 * Ready-to-use billing endpoints backed by {@see BillingManager}. Consumers can
 * point {@see BillingRoutes::register()} at this controller for zero-config
 * billing, or publish/extend it via the `kinetix:make-billing` command.
 */
class BillingController
{
    protected function manager(): BillingManager
    {
        return BillingManager::resolve();
    }

    public function index(Request $request): Response
    {
        $manager = $this->manager();

        $preselectedPlanSlug = $request->session()->pull('selected_plan')
            ?? $request->query('plan');

        return Inertia::render((string) config('kinetix.billing.view', 'Billing/Index'), [
            'plans'       => $manager->plans(),
            'currentPlan' => $manager->currentPlan() !== null
                ? PlanData::fromPlan($manager->currentPlan())
                : null,
            'preselectedPlanSlug'    => $preselectedPlanSlug,
            'intent'                 => $manager->setupIntent(),
            'paymentMethods'         => $manager->paymentMethods(),
            'defaultPaymentMethodId' => $manager->defaultPaymentMethodId(),
            'invoices'               => $manager->invoices(),
            'subscription'           => $manager->subscriptionData(),
            'currency'               => config('kinetix.billing.currency', 'USD'),
            'currencySymbol'         => config('kinetix.billing.currency_symbol', '$'),
            'publishableKey'         => config('cashier.key') ?? config('services.stripe.key'),
            'trialGeneric'           => (bool) config('kinetix.billing.trial_generic', false),
            'invoicesUseStripeUrl'   => (bool) config('kinetix.billing.invoices_use_stripe_url', false),
        ]);
    }

    public function subscribe(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'plan_slug'      => ['required', 'string', 'exists:plans,slug'],
            'payment_method' => ['nullable', 'string'],
            'cycle'          => ['nullable', 'in:monthly,yearly'],
        ]);

        try {
            $this->manager()->subscribe(
                $validated['plan_slug'],
                $validated['payment_method'] ?? null,
                $validated['cycle']          ?? 'monthly',
            );
        } catch (Throwable $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return back()->with('status', (string) trans('kinetix.billing_subscription_updated'));
    }

    public function addPaymentMethod(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'payment_method' => ['required', 'string'],
        ]);

        try {
            $this->manager()->addPaymentMethod($validated['payment_method']);
        } catch (Throwable $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return back()->with('status', (string) trans('kinetix.billing_payment_method_added'));
    }

    public function removePaymentMethod(Request $request): RedirectResponse
    {
        try {
            $this->manager()->removePaymentMethod((string) $request->route('id'));
        } catch (Throwable $e) {
            return back()->withErrors(['message' => $e->getMessage()]);
        }

        return back()->with('status', (string) trans('kinetix.billing_payment_method_removed'));
    }

    public function downloadInvoice(Request $request): mixed
    {
        return $this->manager()->downloadInvoice((string) $request->route('id'));
    }

    public function cancel(Request $request): RedirectResponse
    {
        $this->manager()->cancel();

        return back()->with('status', (string) trans('kinetix.billing_subscription_cancelled'));
    }

    public function resume(Request $request): RedirectResponse
    {
        $this->manager()->resume();

        return back()->with('status', (string) trans('kinetix.billing_subscription_resumed'));
    }
}
