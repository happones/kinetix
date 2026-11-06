import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
import type { KinetixFetchError } from '@/composables/useKinetixHttp';

/** One toast per page, however many surfaces hit a dead descriptor at once. */
export const KINETIX_DESCRIPTOR_EXPIRED_TOAST = 'kinetix-descriptor-expired';

/**
 * Whether a rejection is the server saying the signed descriptor behind the
 * request (calendar, kanban, form recompute) is expired or unreadable: the
 * page is out of date, not the action wrong.
 */
export function isKinetixDescriptorExpired(error: unknown): boolean {
    return (
        error instanceof Error &&
        (error as Partial<KinetixFetchError>).code === 'descriptor_expired'
    );
}

/**
 * The localized "this page is out of date" toast with a Reload action. The
 * reload is an Inertia reload, so component state (an open form's values,
 * scroll) survives and the page gets fresh descriptors. The toast is
 * announced through the toaster's polite live region; its fixed id keeps a
 * burst of rejections down to one toast.
 */
export function toastKinetixDescriptorExpired(
    t: (key: string) => string,
): void {
    toast.error(t('kinetix.descriptor_expired'), {
        id: KINETIX_DESCRIPTOR_EXPIRED_TOAST,
        duration: Number.POSITIVE_INFINITY,
        action: {
            label: t('kinetix.reload_page'),
            // The toaster closes the toast after its action runs.
            onClick: () => router.reload(),
        },
    });
}
