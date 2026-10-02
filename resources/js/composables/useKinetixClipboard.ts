import { onBeforeUnmount, ref } from 'vue';
import type { Ref } from 'vue';

/**
 * Click-to-copy state shared by every Kinetix copy affordance (copyable
 * columns, copyable inputs). `copy()` writes the text and flips `status` to
 * `copied` (or `failed`) for `resetAfter` ms so the trigger can swap its icon
 * and tooltip; the reset timer is cleared on unmount.
 *
 *   const { status, copy } = useKinetixClipboard()
 *   await copy('ada@acme.dev') // → true, status === 'copied' for 2s
 */
export type KinetixClipboardStatus = 'idle' | 'copied' | 'failed';

export interface UseKinetixClipboard {
    status: Ref<KinetixClipboardStatus>;
    copy: (text: string) => Promise<boolean>;
}

/**
 * The async Clipboard API only exists in secure contexts (https, localhost) —
 * an app served over plain http on a LAN address would otherwise fail
 * silently, so fall back to the legacy selection-based copy.
 */
function legacyCopy(text: string): boolean {
    if (typeof document === 'undefined' || !document.execCommand) {
        return false;
    }

    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    Object.assign(textarea.style, {
        position: 'fixed',
        top: '0',
        left: '0',
        opacity: '0',
        pointerEvents: 'none',
    });

    const previousFocus = document.activeElement as HTMLElement | null;
    document.body.appendChild(textarea);
    textarea.select();

    try {
        return document.execCommand('copy');
    } catch {
        return false;
    } finally {
        textarea.remove();
        previousFocus?.focus?.({ preventScroll: true });
    }
}

export async function writeToClipboard(text: string): Promise<boolean> {
    if (typeof navigator !== 'undefined' && navigator.clipboard?.writeText) {
        try {
            await navigator.clipboard.writeText(text);

            return true;
        } catch {
            // Permission denied / document not focused — try the legacy path.
        }
    }

    return legacyCopy(text);
}

export function useKinetixClipboard(resetAfter = 2000): UseKinetixClipboard {
    const status = ref<KinetixClipboardStatus>('idle');
    let resetTimer: ReturnType<typeof setTimeout> | null = null;

    const clearTimer = (): void => {
        if (resetTimer) {
            clearTimeout(resetTimer);
            resetTimer = null;
        }
    };

    const copy = async (text: string): Promise<boolean> => {
        const ok = await writeToClipboard(text);

        status.value = ok ? 'copied' : 'failed';
        clearTimer();
        resetTimer = setTimeout(() => {
            status.value = 'idle';
            resetTimer = null;
        }, resetAfter);

        return ok;
    };

    onBeforeUnmount(clearTimer);

    return { status, copy };
}
