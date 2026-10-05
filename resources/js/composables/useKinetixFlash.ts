import { router, usePage } from '@inertiajs/vue3';
import type {
    KinetixFlashAlert,
    KinetixFlashPayload,
    KinetixFlashToast,
} from '@/types/kinetix';

/**
 * Kinetix's slice of Inertia's page-level `flash` — what `KinetixFlash` (PHP)
 * and `useKinetixFlash()` (client) put under `flash.kinetix`.
 *
 * Flash is not page props: the browser history never stores it, so Back and
 * Forward can't replay it. And every visit replaces it — a poll or a partial
 * reload included — which is why consumers collect it from the `flash` event
 * (`onKinetixFlash`) instead of rendering `page.flash` directly.
 */
export const KINETIX_FLASH_KEY = 'kinetix';

/** Kinetix's payload out of a page-level `flash` object (never null). */
export function kinetixFlashOf(flash: unknown): KinetixFlashPayload {
    const payload = (flash as Record<string, unknown> | null | undefined)?.[
        KINETIX_FLASH_KEY
    ];

    return payload !== null && typeof payload === 'object'
        ? (payload as KinetixFlashPayload)
        : {};
}

/**
 * Call `handler` with the flash the current page arrived with, then with every
 * new one. Returns the unsubscribe — call it on unmount. Safe outside a full
 * Inertia app (tests, a standalone widget): it then just does nothing.
 */
export function onKinetixFlash(
    handler: (payload: KinetixFlashPayload) => void,
): () => void {
    try {
        handler(kinetixFlashOf(usePage().flash));
    } catch {
        // No page to read.
    }

    try {
        return router.on('flash', (event) =>
            handler(kinetixFlashOf(event.detail.flash)),
        );
    } catch {
        return () => {};
    }
}

/** `crypto.randomUUID` only exists in secure contexts (https / localhost). */
function flashId(): string {
    return (
        globalThis.crypto?.randomUUID?.() ??
        `kx-${Date.now().toString(36)}-${Math.random().toString(36).slice(2)}`
    );
}

export interface KinetixFlashToastOptions {
    description?: string | null;
    /** ms on screen; unset = the toaster's default. */
    duration?: number | null;
}

export type KinetixFlashAlertOptions = Partial<
    Pick<
        KinetixFlashAlert,
        'id' | 'description' | 'color' | 'variant' | 'icon' | 'dismissible'
    >
>;

/**
 * Flash from the client, no server round-trip: the same toasts and alerts
 * `KinetixFlash` sends, shown by the same `<KinetixToaster>` and
 * `<KinetixFlashAlerts>`. Like a server flash, an alert is gone on the next
 * page.
 *
 *     const flash = useKinetixFlash();
 *     flash.success(t('app.copied'));
 *     flash.alert(t('app.offline'), { color: 'warning' });
 */
export function useKinetixFlash() {
    function push(bucket: 'toasts' | 'alerts', entry: object): void {
        router.flash((current) => {
            const payload = kinetixFlashOf(current);
            const entries = (payload[bucket] ?? []) as object[];

            return {
                ...current,
                [KINETIX_FLASH_KEY]: {
                    ...payload,
                    [bucket]: [...entries, entry],
                },
            };
        });
    }

    function toast(
        message: string,
        type: KinetixFlashToast['type'] = 'success',
        options: KinetixFlashToastOptions = {},
    ): void {
        push('toasts', {
            id: flashId(),
            type,
            message,
            description: options.description ?? null,
            duration: options.duration ?? null,
        } satisfies KinetixFlashToast);
    }

    function alert(
        title: string,
        options: KinetixFlashAlertOptions = {},
    ): void {
        push('alerts', {
            id: options.id ?? flashId(),
            title,
            description: options.description ?? null,
            color: options.color ?? 'info',
            variant: options.variant ?? 'soft',
            icon: options.icon ?? null,
            dismissible: options.dismissible ?? true,
            persistent: false,
        } satisfies KinetixFlashAlert);
    }

    return {
        toast,
        success: (message: string, options?: KinetixFlashToastOptions) =>
            toast(message, 'success', options),
        error: (message: string, options?: KinetixFlashToastOptions) =>
            toast(message, 'error', options),
        warning: (message: string, options?: KinetixFlashToastOptions) =>
            toast(message, 'warning', options),
        info: (message: string, options?: KinetixFlashToastOptions) =>
            toast(message, 'info', options),
        alert,
    };
}
