<?php

declare(strict_types=1);

namespace Happones\Kinetix\Billing;

use Happones\Kinetix\Billing\Exceptions\BillingActionException;
use Happones\Kinetix\Data\BillingAbilitiesData;
use Happones\Kinetix\Data\PlanData;
use Happones\Kinetix\Flash\KinetixFlash;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Inertia\Inertia;
use Inertia\Response;
use Laravel\Cashier\Exceptions\IncompletePayment;
use Stripe\Exception\CardException;
use Symfony\Component\HttpFoundation\Response as HttpResponse;
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

    /**
     * The request's billing manager, once the user may `view` or `manage` its
     * billable ({@see BillingManager::allows()}); a 403 otherwise. Every
     * endpoint goes through here: a member who may read the billing page can
     * still not change the plan, the card or the subscription.
     */
    protected function authorizedManager(Request $request, string $ability): BillingManager
    {
        $manager = $this->manager();

        if (! BillingManager::allows($ability, $request->user(), $manager->billable())) {
            abort(403, $ability === 'manage' ? (string) __('kinetix.billing_manage_forbidden') : '');
        }

        return $manager;
    }

    public function index(Request $request): Response
    {
        $manager   = $this->authorizedManager($request, 'view');
        $canManage = BillingManager::allows('manage', $request->user(), $manager->billable());

        // Back from confirming a payment on Cashier's page, the subscription is
        // still `incomplete` here until Stripe's webhook says otherwise.
        $manager->refreshIncompleteSubscription();

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
            'preselectedPlanSlug' => $preselectedPlanSlug,
            'can'                 => new BillingAbilitiesData(view: true, manage: $canManage),
            // Only for someone who may add a card: creating the intent makes
            // the billable a Stripe customer.
            'intent'                 => $canManage ? $manager->setupIntent() : null,
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
            'plansLocked'            => $manager->plansLocked(),
            'invoicesUseStripeUrl'   => (bool) config('kinetix.billing.invoices_use_stripe_url', false),
        ]);
    }

    public function subscribe(Request $request): HttpResponse
    {
        $manager = $this->authorizedManager($request, 'manage');

        $validated = $request->validate([
            'plan_slug'      => ['required', 'string', 'exists:plans,slug'],
            'payment_method' => ['nullable', 'string'],
            'cycle'          => ['nullable', 'in:monthly,yearly'],
        ]);

        return $this->attempt(fn () => $manager->subscribe(
            $validated['plan_slug'],
            $validated['payment_method'] ?? null,
            $validated['cycle']          ?? 'monthly',
        ), 'kinetix.billing_subscription_updated');
    }

    public function addPaymentMethod(Request $request): HttpResponse
    {
        $manager = $this->authorizedManager($request, 'manage');

        $validated = $request->validate([
            'payment_method' => ['required', 'string'],
        ]);

        return $this->attempt(
            fn () => $manager->addPaymentMethod($validated['payment_method']),
            'kinetix.billing_payment_method_added',
        );
    }

    public function removePaymentMethod(Request $request): HttpResponse
    {
        $manager = $this->authorizedManager($request, 'manage');

        return $this->attempt(
            fn () => $manager->removePaymentMethod((string) $request->route('id')),
            'kinetix.billing_payment_method_removed',
        );
    }

    public function downloadInvoice(Request $request): mixed
    {
        return $this->authorizedManager($request, 'view')->downloadInvoice((string) $request->route('id'));
    }

    public function cancel(Request $request): HttpResponse
    {
        $manager = $this->authorizedManager($request, 'manage');

        return $this->attempt(fn () => $manager->cancel(), 'kinetix.billing_subscription_cancelled');
    }

    public function resume(Request $request): HttpResponse
    {
        $manager = $this->authorizedManager($request, 'manage');

        return $this->attempt(fn () => $manager->resume(), 'kinetix.billing_subscription_resumed');
    }

    /**
     * The starter kits' `toast` flash. Kinetix supports inertia-laravel 2.0,
     * whose factory has no `flash()` (added in 2.0.16): there it goes to the
     * session, where an app on that version reads its flash from.
     */
    protected function toast(string $type, string $message): void
    {
        $toast = ['type' => $type, 'message' => $message];

        if (KinetixFlash::inertiaHasFlash()) {
            Inertia::flash('toast', $toast);

            return;
        }

        session()->flash('toast', $toast);
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
     *
     * That page is a plain Blade view, not an Inertia one. Redirected to from
     * an Inertia visit, the XHR followed the redirect and Inertia showed the
     * HTML in its error dialog — an iframe with a `null` origin, where neither
     * the page's Vue nor Stripe.js can run, so the payment could never be
     * confirmed. `Inertia::location()` makes the browser load it as a page; a
     * plain request still gets the redirect.
     */
    protected function attempt(callable $action, string $successKey): HttpResponse
    {
        try {
            $action();
        } catch (Throwable $e) {
            if ($e instanceof IncompletePayment && Route::has('cashier.payment')) {
                return Inertia::location(route('cashier.payment', [
                    $e->payment->asStripePaymentIntent()->id,
                    'redirect' => url()->previous(),
                ]));
            }

            $message = match (true) {
                $e instanceof BillingActionException => $e->getMessage(),
                $e instanceof CardException          => (string) ($e->getError()->message ?? $e->getMessage()),
                default                              => (string) __('kinetix.billing_action_failed'),
            };

            if (! $e instanceof BillingActionException && ! $e instanceof CardException) {
                report($e);
            }

            $this->toast('error', $message);

            return back()->withErrors(['message' => $message]);
        }

        $message = (string) __($successKey);

        $this->toast('success', $message);

        return back()->with('status', $message);
    }
}
