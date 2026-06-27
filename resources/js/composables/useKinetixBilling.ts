import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

/**
 * Endpoint URLs for the billing actions. Resolve them however the host app
 * prefers — Ziggy `route()`, Wayfinder, or plain strings — and pass them in.
 * `removePaymentMethod` takes the payment-method id so the URL can embed it.
 */
export interface KinetixBillingEndpoints {
    subscribe: string;
    cancel: string;
    resume: string;
    addPaymentMethod: string;
    removePaymentMethod: (id: string) => string;
}

/**
 * Centralises the Inertia visits behind the billing UI so pages stay thin.
 * Tracks a shared `processing` flag and preserves scroll on every visit.
 */
export function useKinetixBilling(endpoints: KinetixBillingEndpoints) {
    const processing = ref(false);

    function visitOptions(overrides: Record<string, unknown> = {}) {
        return {
            preserveScroll: true,
            onStart: () => {
                processing.value = true;
            },
            onFinish: () => {
                processing.value = false;
            },
            ...overrides,
        };
    }

    function subscribe(
        planSlug: string,
        paymentMethod: string | null = null,
        cycle: 'monthly' | 'yearly' = 'monthly',
    ): void {
        router.post(
            endpoints.subscribe,
            { plan_slug: planSlug, payment_method: paymentMethod, cycle },
            visitOptions(),
        );
    }

    function addPaymentMethod(paymentMethod: string): void {
        router.post(
            endpoints.addPaymentMethod,
            { payment_method: paymentMethod },
            visitOptions(),
        );
    }

    function removePaymentMethod(id: string): void {
        router.delete(endpoints.removePaymentMethod(id), visitOptions());
    }

    function cancel(): void {
        router.post(endpoints.cancel, {}, visitOptions());
    }

    function resume(): void {
        router.post(endpoints.resume, {}, visitOptions());
    }

    return {
        processing,
        subscribe,
        addPaymentMethod,
        removePaymentMethod,
        cancel,
        resume,
    };
}
