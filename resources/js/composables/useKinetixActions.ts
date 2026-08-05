import { router } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import { kinetixFetch } from '@/composables/useKinetixHttp';
import type { KinetixAction } from '@/types/kinetix';

/**
 * Execute a Kinetix action's behaviour: fire a browser event, perform an
 * Inertia visit, fire a background HTTP request, open a new tab, or navigate.
 *
 * Awaitable: the `httpRequest` and `inertiaVisit` branches resolve when the
 * request finishes, so callers can show a pending state and block double
 * submits. A failed background request **rejects** (the caller decides how to
 * surface it) — the only toast fired here is the action's configured success
 * message. Shared by tables, page action bars, and any component that renders
 * actions.
 */
export async function executeAction(
    action: KinetixAction,
    extraData: Record<string, any> = {},
): Promise<void> {
    if (action.dispatchEvent) {
        window.dispatchEvent(
            new CustomEvent(`kinetix:${action.dispatchEvent}`, {
                detail: { ...(action.dispatchData ?? {}), ...extraData },
                bubbles: true,
            }),
        );
    }

    if (action.inertiaVisit && action.url) {
        const { method = 'get', ...visitOptions } = action.inertiaVisit;

        // Resolve when Inertia finishes so the caller's pending state spans the
        // visit (guarding a double click before navigation completes).
        await new Promise<void>((resolve) => {
            router.visit(action.url as string, {
                method: method as any,
                data: extraData,
                ...visitOptions,
                onFinish: () => resolve(),
            });
        });

        return;
    }

    // Background HTTP request (plain XHR, no Inertia) — e.g. ExportAction. Awaited
    // so the caller knows when it's done; rejects on a non-2xx (kinetixFetch
    // surfaces the server's message).
    if (action.httpRequest && action.url) {
        const { method = 'post', toast: toastMessage } = action.httpRequest;

        await kinetixFetch(action.url, {
            method: String(method),
            body: extraData ?? {},
        });

        if (toastMessage) {
            toast.success(toastMessage as string);
        }

        return;
    }

    if (!action.url) {
        return;
    }

    // Open the file in the global preview lightbox instead of navigating.
    if (action.isPreview) {
        window.dispatchEvent(
            new CustomEvent('kinetix:preview', {
                detail: {
                    url: action.url,
                    type: action.previewType ?? 'auto',
                    label: action.label,
                },
            }),
        );

        return;
    }

    // Force a file download (attachment) instead of navigating.
    if (action.isDownload) {
        const link = document.createElement('a');
        link.href = action.url;
        link.download = '';
        link.rel = 'noopener';
        document.body.appendChild(link);
        link.click();
        link.remove();

        return;
    }

    if (action.shouldOpenInNewTab) {
        // noopener,noreferrer: the opened page can't reach back via window.opener.
        window.open(action.url, '_blank', 'noopener,noreferrer');

        return;
    }

    const isInternal =
        action.url.startsWith('/') ||
        action.url.startsWith(window.location.origin);

    if (isInternal) {
        router.visit(action.url);

        return;
    }

    window.location.href = action.url;
}

/**
 * Reactive confirmation gate for actions. Actions flagged with
 * `requiresConfirmation` open a modal; everything else runs immediately.
 *
 * Guards against double execution: while an action is in flight `processing` is
 * true, repeat clicks are ignored, and the confirm modal stays open (disabled)
 * until the request resolves. Returns the modal state plus `requestAction` (the
 * click entry point), `confirm`, `cancel`, and `processing`.
 */
export function useActionConfirmation() {
    const { t } = useI18n();
    const pendingAction = ref<KinetixAction | null>(null);
    const pendingExtra = ref<Record<string, any>>({});
    const isConfirmOpen = ref(false);
    const processing = ref(false);
    // Name of the action in flight, so the CLICKED button can show its
    // spinner while its siblings only disable.
    const processingAction = ref<string | null>(null);

    const run = async (
        action: KinetixAction,
        extraData: Record<string, any> = {},
    ): Promise<void> => {
        if (processing.value) {
            return;
        }

        processing.value = true;
        processingAction.value = action.name ?? null;

        try {
            await executeAction(action, extraData);
        } catch (e) {
            toast.error(
                e instanceof Error && e.message
                    ? e.message
                    : t('kinetix.action_failed'),
            );
        } finally {
            processing.value = false;
            processingAction.value = null;
        }
    };

    // `extraData` is merged into an `inertiaVisit`/`httpRequest` body and into a
    // `dispatchEvent`'s detail — e.g. a per-row action passes its `record`.
    const requestAction = (
        action: KinetixAction,
        extraData: Record<string, any> = {},
    ): void => {
        if (processing.value) {
            return;
        }

        if (action.requiresConfirmation) {
            pendingAction.value = action;
            pendingExtra.value = extraData;
            isConfirmOpen.value = true;

            return;
        }

        void run(action, extraData);
    };

    const confirm = async (): Promise<void> => {
        const action = pendingAction.value;

        if (processing.value || !action) {
            return;
        }

        await run(action, pendingExtra.value);

        // Close only after the action resolves, so the modal shows its pending
        // state and can't be dismissed (or re-confirmed) mid-flight.
        isConfirmOpen.value = false;
        pendingAction.value = null;
        pendingExtra.value = {};
    };

    const cancel = (): void => {
        if (processing.value) {
            return;
        }

        isConfirmOpen.value = false;
        pendingAction.value = null;
        pendingExtra.value = {};
    };

    return {
        pendingAction,
        isConfirmOpen,
        processing,
        processingAction,
        requestAction,
        confirm,
        cancel,
    };
}
