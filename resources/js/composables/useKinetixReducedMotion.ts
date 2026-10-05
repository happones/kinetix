import { usePage } from '@inertiajs/vue3';
import { computed, getCurrentScope, onScopeDispose, ref } from 'vue';
import type { ComputedRef } from 'vue';
import type { KinetixSharedProps } from '@/types/kinetix';

/**
 * Whether Kinetix's JS-driven motion (transition presets, auto-rotation) must
 * stand still. Three sources, any one of them wins:
 *
 * - the OS `prefers-reduced-motion: reduce` setting;
 * - the user's Kinetix preference — the `kx-reduce-motion` class the
 *   accessibility module puts on `<html>`;
 * - the host's `kinetix.motion = 'reduced'` config (shared as
 *   `kinetix_config.motion`), for an app that wants no motion at all.
 *
 * CSS animations are already covered by the accessibility plugin's global
 * guard; this exists for what CSS can't stop — a rotation timer, or a
 * `<Transition>` that would otherwise wait out its duration.
 *
 * Reactive: flipping the OS setting or the preference toggle applies at once.
 * One media listener and one class observer serve every consumer, and both
 * are released when the last consumer's scope is disposed.
 */
const QUERY = '(prefers-reduced-motion: reduce)';
const PREFERENCE_CLASS = 'kx-reduce-motion';

const systemReduced = ref(false);
const preferenceReduced = ref(false);

let consumers = 0;
let media: MediaQueryList | null = null;
let observer: MutationObserver | null = null;

function syncSystem(): void {
    systemReduced.value = media?.matches ?? false;
}

function syncPreference(): void {
    preferenceReduced.value =
        document.documentElement.classList.contains(PREFERENCE_CLASS);
}

function resync(): void {
    if (typeof document === 'undefined') {
        return;
    }

    syncSystem();
    syncPreference();
}

function attach(): void {
    if (typeof window === 'undefined' || typeof document === 'undefined') {
        return;
    }

    media = window.matchMedia?.(QUERY) ?? null;
    syncSystem();
    media?.addEventListener?.('change', syncSystem);

    syncPreference();

    if (typeof MutationObserver !== 'undefined') {
        observer = new MutationObserver(syncPreference);
        observer.observe(document.documentElement, {
            attributes: true,
            attributeFilter: ['class'],
        });
    }
}

function detach(): void {
    media?.removeEventListener?.('change', syncSystem);
    media = null;
    observer?.disconnect();
    observer = null;
}

function hostReduced(): () => boolean {
    try {
        const page = usePage<KinetixSharedProps>();

        return () => page.props?.kinetix_config?.motion === 'reduced';
    } catch {
        // Mounted outside a full Inertia app (tests, a standalone widget).
        return () => false;
    }
}

export function useKinetixReducedMotion(): ComputedRef<boolean> {
    const host = hostReduced();

    if (consumers === 0) {
        attach();
    } else {
        // The shared observer reports a class change asynchronously; a
        // component mounting right after the change must not render with the
        // stale value (and autoplay for a user who just asked for less motion).
        resync();
    }

    // A caller outside any effect scope never releases its share, which only
    // keeps the (shared, global) listeners attached — never a leak per call.
    consumers++;

    if (getCurrentScope()) {
        onScopeDispose(() => {
            consumers--;

            if (consumers === 0) {
                detach();
            }
        });
    }

    return computed(
        () => systemReduced.value || preferenceReduced.value || host(),
    );
}
