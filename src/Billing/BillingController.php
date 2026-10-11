<?php

declare(strict_types=1);

namespace Happones\Kinetix\Billing;

use Happones\Kinetix\Billing\Exceptions\BillingActionException;
use Happones\Kinetix\Data\PlanData;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Stripe\Exception\CardException;
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

        // Null without a subscription or trial: an all-null record rendered
        // as a blank status pill on every free team's page.
        $subscription = $manager->subscriptionData();

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
            'subscription'           => $subscription['status'] !== null || $subscription['onTrial'] ? $subscription : null,
            'usage'                  => $manager->usage(),
            'currency'               => config('kinetix.billing.currency', 'USD'),
            'currencySymbol'         => config('kinetix.billing.currency_symbol', '$'),
            'publishableKey'         => config('cashier.key') ?? config('services.stripe.key'),
            'trialGeneric'           => (bool) config('kinetix.billing.trial_generic', false),
            'trialAvailable'         => $manager->genericTrialAvailable(),
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

        return $this->attempt(fn () => $this->manager()->subscribe(
            $validated['plan_slug'],
            $validated['payment_method'] ?? null,
            $validated['cycle']          ?? 'monthly',
        ), 'kinetix.billing_subscription_updated');
    }

    public function addPaymentMethod(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'payment_method' => ['required', 'string'],
        ]);

        return $this->attempt(
            fn () => $this->manager()->addPaymentMethod($validated['payment_method']),
            'kinetix.billing_payment_method_added',
        );
    }

    public function removePaymentMethod(Request $request): RedirectResponse
    {
        return $this->attempt(
            fn () => $this->manager()->removePaymentMethod((string) $request->route('id')),
            'kinetix.billing_payment_method_removed',
        );
    }

    public function downloadInvoice(Request $request): mixed
    {
        return $this->manager()->downloadInvoice((string) $request->route('id'));
    }

    public function cancel(Request $request): RedirectResponse
    {
        return $this->attempt(fn () => $this->manager()->cancel(), 'kinetix.billing_subscription_cancelled');
    }

    public function resume(Request $request): RedirectResponse
    {
        return $this->attempt(fn () => $this->manager()->resume(), 'kinetix.billing_subscription_resumed');
    }

    /**
     * Run a billing action and answer with a toast either way. The outcome
     * used to go to `session('status')` and `errors.message`, which no page
     * rendered: a card that failed or a plan that changed said nothing.
     *
     * The `toast` flash is the starter kits' one. A {@see BillingActionException}
     * or a declined card says why; any other failure is reported and answered
     * with a generic message, never its internals. A payment Stripe wants
     * confirmed (3-D Secure) goes to Cashier's confirmation page.
     */
    protected function attempt(callable $action, string $successKey): RedirectResponse
    {
        try {
            $action();
        } catch (Throwable $e) {
            if ($e instanceof IncompletePayment && Route::has('cashier.payment')) {
                return redirect()->route('cashier.payment', [
                    $e->payment->asStripePaymentIntent()->id,
                    'redirect' => url()->previous(),
                ]);
            }

            $message = match (true) {
                $e instanceof BillingActionException => $e->getMessage(),
                $e instanceof CardException          => (string) ($e->getError()->message ?? $e->getMessage()),
                default                              => (string) __('kinetix.billing_action_failed'),
            };

            if (! $e instanceof BillingActionException && ! $e instanceof CardException) {
                report($e);
            }

            Inertia::flash('toast', ['type' => 'error', 'message' => $message]);

            return back()->withErrors(['message' => $message]);
        }

        $message = (string) __($successKey);

        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return back()->with('status', $message);
    }
}
