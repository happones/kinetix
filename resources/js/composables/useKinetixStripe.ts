import { onBeforeUnmount, ref, shallowRef } from 'vue';

/**
 * Wraps Stripe Elements with shadcn design tokens.
 *
 * Stripe renders the card field inside a cross-origin iframe, so it cannot
 * inherit page CSS — it must be styled through a JS `style` object of concrete
 * color strings. shadcn tokens are CSS variables (HSL channels like
 * `240 10% 3.9%` on classic shadcn, or `oklch(...)` on newer Tailwind v4 kits),
 * neither of which Stripe parses. We resolve each token to a browser-computed
 * `rgb()` value via a throwaway probe element, so any token format works.
 *
 * Dark/light mode is honoured live: a MutationObserver on `<html>` rebuilds the
 * Element style whenever the theme class/attribute toggles. The observer and the
 * mounted Element are torn down on unmount to avoid leaks.
 *
 * Stripe.js is optional: we use a global `window.Stripe` when present (script
 * tag) and otherwise lazily import `@stripe/stripe-js` if the host installed it.
 */

type StripeStyle = {
    base: Record<string, unknown>;
    invalid: Record<string, unknown>;
};

export interface UseKinetixStripeOptions {
    publishableKey: string;
    /** The element type to create. Defaults to the combined `card` field. */
    elementType?: string;
    /** Selector / element the theme toggle mutates. Defaults to `<html>`. */
    themeRoot?: HTMLElement;
}

const HSL_CHANNELS = /^[\d.]+\s+[\d.]+%\s+[\d.]+%$/;

/**
 * Resolve a shadcn CSS token (e.g. `--foreground`) to a concrete `rgb()` string
 * that Stripe can consume, regardless of whether the token holds HSL channels,
 * an `oklch()` color, a hex value, etc.
 */
function resolveToken(token: string, fallback: string): string {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return fallback;
    }

    const raw = getComputedStyle(document.documentElement)
        .getPropertyValue(token)
        .trim();

    // Bare HSL channels need wrapping; full colors are used as-is.
    const expression =
        raw === '' ? fallback : HSL_CHANNELS.test(raw) ? `hsl(${raw})` : raw;

    const probe = document.createElement('span');
    probe.style.color = expression;
    probe.style.display = 'none';
    document.body.appendChild(probe);
    const resolved = getComputedStyle(probe).color;
    document.body.removeChild(probe);

    // An invalid expression leaves color empty / transparent — fall back then.
    return resolved && resolved !== 'rgba(0, 0, 0, 0)' ? resolved : fallback;
}

export function useKinetixStripe(options: UseKinetixStripeOptions) {
    const stripe = shallowRef<any>(null);
    const elements = shallowRef<any>(null);
    const element = shallowRef<any>(null);
    const ready = ref(false);
    const error = ref<string | null>(null);

    let observer: MutationObserver | null = null;

    async function loadStripeInstance(key: string): Promise<any> {
        if (typeof window !== 'undefined' && (window as any).Stripe) {
            return (window as any).Stripe(key);
        }

        try {
            // Variable specifier + @vite-ignore so bundlers don't require the optional
            // dependency at build time when the host relies on the global script tag.
            const specifier = '@stripe/stripe-js';
            const mod: any = await import(/* @vite-ignore */ specifier);

            return await mod.loadStripe(key);
        } catch {
            return null;
        }
    }

    /**
     * Build a Stripe Elements `style` object from the live shadcn tokens.
     */
    function buildStyle(): StripeStyle {
        return {
            base: {
                color: resolveToken('--foreground', 'rgb(15, 23, 42)'),
                iconColor: resolveToken('--foreground', 'rgb(15, 23, 42)'),
                fontFamily:
                    'ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, sans-serif',
                fontSmoothing: 'antialiased',
                fontSize: '15px',
                '::placeholder': {
                    color: resolveToken(
                        '--muted-foreground',
                        'rgb(148, 163, 184)',
                    ),
                },
            },
            invalid: {
                color: resolveToken('--destructive', 'rgb(239, 68, 68)'),
                iconColor: resolveToken('--destructive', 'rgb(239, 68, 68)'),
            },
        };
    }

    /**
     * Re-apply the token-derived style to the live Element (used on theme toggle).
     */
    function applyTheme(): void {
        if (element.value) {
            element.value.update({ style: buildStyle() });
        }
    }

    function watchTheme(): void {
        if (typeof MutationObserver === 'undefined') {
            return;
        }

        const root = options.themeRoot ?? document.documentElement;
        observer = new MutationObserver(() => applyTheme());
        observer.observe(root, {
            attributes: true,
            attributeFilter: ['class', 'data-theme', 'style'],
        });
    }

    /**
     * Create and mount the Stripe Element into the given selector/node.
     */
    async function mount(target: string | HTMLElement): Promise<void> {
        stripe.value = await loadStripeInstance(options.publishableKey);

        if (!stripe.value) {
            error.value =
                'Stripe.js is unavailable. Add the Stripe script tag or install @stripe/stripe-js.';

            return;
        }

        elements.value = stripe.value.elements();
        element.value = elements.value.create(options.elementType ?? 'card', {
            style: buildStyle(),
        });
        element.value.on('ready', () => {
            ready.value = true;
        });
        element.value.mount(target);

        watchTheme();
    }

    /**
     * Confirm a SetupIntent with the mounted card, returning the payment method id.
     */
    async function confirmCardSetup(clientSecret: string): Promise<{
        paymentMethodId: string | null;
        error: string | null;
    }> {
        if (!stripe.value || !element.value) {
            return { paymentMethodId: null, error: 'Stripe is not ready.' };
        }

        const result = await stripe.value.confirmCardSetup(clientSecret, {
            payment_method: { card: element.value },
        });

        if (result.error) {
            return {
                paymentMethodId: null,
                error: result.error.message ?? 'Card error.',
            };
        }

        return {
            paymentMethodId: result.setupIntent?.payment_method ?? null,
            error: null,
        };
    }

    function destroy(): void {
        observer?.disconnect();
        observer = null;

        if (element.value) {
            element.value.unmount();
            element.value.destroy();
            element.value = null;
        }

        elements.value = null;
        stripe.value = null;
        ready.value = false;
    }

    onBeforeUnmount(destroy);

    return {
        stripe,
        element,
        ready,
        error,
        mount,
        applyTheme,
        confirmCardSetup,
        destroy,
    };
}
