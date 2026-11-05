import type { Ref, VNode } from 'vue';
import {
    defineKinetixLazy,
    KinetixLazyBlockSkeleton,
    preloadKinetixLazy,
} from '@/composables/kinetixLazy';
import { FOCUSABLE_SELECTOR } from '@/composables/useKinetixFocusTrap';
import { preloadKinetixFormFields } from './Form/kinetixFormFieldLoaders';

/**
 * The form and infolist that tables and relation managers show inside their
 * modals (create/edit, form actions, view). A plain table page never opens
 * one, so their code downloads when a modal first opens, behind a busy block
 * skeleton.
 *
 * This module imports no component statically, on purpose.
 */
export const KINETIX_LAZY_FORM = defineKinetixLazy(
    () => import('./KinetixForm.vue'),
    { loading: KinetixLazyBlockSkeleton },
);

export const KINETIX_LAZY_INFOLIST = defineKinetixLazy(
    () => import('./KinetixInfolist.vue'),
    { loading: KinetixLazyBlockSkeleton },
);

/**
 * Start the modal form's download together with the field types its schema
 * uses, so neither waits for the other. Without a schema yet (an edit still
 * fetching its record), only the form starts.
 */
export function preloadKinetixModalForm(schema?: unknown): Promise<void> {
    return Promise.all([
        preloadKinetixLazy([KINETIX_LAZY_FORM]),
        Array.isArray(schema)
            ? preloadKinetixFormFields(schema)
            : Promise.resolve(),
    ]).then(() => undefined);
}

/**
 * A lazy form that arrived inside an already-open modal: the modal placed
 * focus when it opened, before the form existed, so a field marked
 * `autofocus` never got it. Hand focus to that field — what a warm open
 * would have focused — but only while focus still sits where the modal put
 * it (its first stop, or the panel itself). A user who already moved on
 * keeps their place.
 */
export function focusArrivedModalBody(body: Element | null): void {
    const dialog = body?.closest('[role="dialog"]');

    if (!dialog || typeof document === 'undefined') {
        return;
    }

    const target = dialog.querySelector<HTMLElement>('[autofocus]');
    const active = document.activeElement;

    if (!target || target === active) {
        return;
    }

    const onInitialTarget =
        active === document.body ||
        active === null ||
        active === dialog.querySelector(FOCUSABLE_SELECTOR) ||
        (dialog.contains(active) && active.getAttribute('tabindex') === '-1');

    if (onInitialTarget) {
        target.focus();
    }
}

/**
 * Vnode hooks for a lazy modal form (`@vue:mounted` / `@vue:unmounted`):
 * `mounted` turns true once the real `<form>` is in the page — not its
 * loading skeleton — and false when it leaves, so the modal's footer submit
 * can stay busy until there is a form to submit. Arrival hands focus on (see
 * focusArrivedModalBody).
 */
export function kinetixModalFormHooks(mounted: Ref<boolean>): {
    mounted: (vnode: VNode) => void;
    unmounted: (vnode: VNode) => void;
} {
    const isForm = (vnode: VNode): boolean =>
        typeof HTMLFormElement !== 'undefined' &&
        vnode.el instanceof HTMLFormElement;

    return {
        mounted: (vnode) => {
            if (isForm(vnode)) {
                mounted.value = true;
                focusArrivedModalBody(vnode.el as HTMLFormElement);
            }
        },
        unmounted: (vnode) => {
            if (isForm(vnode)) {
                mounted.value = false;
            }
        },
    };
}
