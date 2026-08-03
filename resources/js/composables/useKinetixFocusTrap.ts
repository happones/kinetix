import { nextTick, onBeforeUnmount, useId, watch } from 'vue';

/**
 * Tab stops inside a dialog. Negative `tabindex` is excluded so the dialog panel
 * itself (which carries `tabindex="-1"` as a focus fallback) is never a stop.
 */
const FOCUSABLE_SELECTOR = [
    'a[href]',
    'area[href]',
    'button:not([disabled])',
    'input:not([disabled]):not([type="hidden"])',
    'select:not([disabled])',
    'textarea:not([disabled])',
    'summary',
    'iframe',
    'audio[controls]',
    'video[controls]',
    '[contenteditable]:not([contenteditable="false"])',
    '[tabindex]:not([tabindex^="-"])',
].join(',');

export interface UseKinetixFocusTrapOptions {
    /** Reactive getter for whether the dialog is open. */
    active: () => boolean;
    /** The dialog panel element — the boundary Tab must not escape. */
    container: () => HTMLElement | null;
    /**
     * Frames to wait for the panel to appear. Hand-rolled dialogs teleport only
     * after `onMounted` flips their `isMounted` flag, so the element does not
     * exist on the tick the trap engages.
     */
    maxTicks?: number;
}

export interface UseKinetixFocusTrap {
    /** Put on the dialog heading and in the dialog's `aria-labelledby`. */
    headingId: string;
    /** The current Tab stops inside the panel, in DOM order. */
    focusables: () => HTMLElement[];
}

/**
 * Modal focus management for the hand-rolled `Teleport` dialogs (ConfirmModal,
 * Sheet): moves focus into the panel when it opens, keeps Tab/Shift+Tab cycling
 * inside it so background content is unreachable, and restores focus to the
 * element that opened it on close. Listeners are attached only while open and
 * always removed on unmount.
 *
 * Escape handling stays with each dialog — only it knows whether dismissal is
 * currently allowed (e.g. ConfirmModal refuses while `processing`).
 */
export function useKinetixFocusTrap(
    options: UseKinetixFocusTrapOptions,
): UseKinetixFocusTrap {
    const headingId = `kinetix-dialog-${useId()}`;
    const maxTicks = options.maxTicks ?? 5;

    let restoreTo: HTMLElement | null = null;
    let listening = false;

    const focusables = (): HTMLElement[] => {
        const root = options.container();

        if (!root) {
            return [];
        }

        return Array.from(
            root.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR),
        );
    };

    const onKeydown = (event: KeyboardEvent): void => {
        if (event.key !== 'Tab') {
            return;
        }

        const root = options.container();

        if (!root) {
            return;
        }

        const stops = focusables();

        if (stops.length === 0) {
            event.preventDefault();
            root.focus();

            return;
        }

        const first = stops[0];
        const last = stops[stops.length - 1];
        const current = document.activeElement as HTMLElement | null;

        if (!current || !root.contains(current)) {
            event.preventDefault();
            (event.shiftKey ? last : first).focus();

            return;
        }

        if (event.shiftKey && current === first) {
            event.preventDefault();
            last.focus();

            return;
        }

        if (!event.shiftKey && current === last) {
            event.preventDefault();
            first.focus();
        }
    };

    const focusPanel = async (): Promise<void> => {
        for (let tick = 0; tick < maxTicks; tick++) {
            await nextTick();

            if (!options.active()) {
                return;
            }

            const root = options.container();

            if (root) {
                (focusables()[0] ?? root).focus();

                return;
            }
        }
    };

    const engage = (): void => {
        if (listening) {
            return;
        }

        const active = document.activeElement as HTMLElement | null;
        restoreTo = active && active !== document.body ? active : null;

        window.addEventListener('keydown', onKeydown, true);
        listening = true;

        void focusPanel();
    };

    const release = (): void => {
        if (!listening) {
            return;
        }

        window.removeEventListener('keydown', onKeydown, true);
        listening = false;

        const target = restoreTo;
        restoreTo = null;

        if (target?.isConnected) {
            target.focus();
        }
    };

    watch(
        options.active,
        (isActive) => {
            if (typeof window === 'undefined') {
                return;
            }

            if (isActive) {
                engage();

                return;
            }

            release();
        },
        { immediate: true },
    );

    onBeforeUnmount(release);

    return { headingId, focusables };
}
