import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, nextTick } from 'vue';

const pageProps: Record<string, unknown> = {};
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: pageProps }) }));

import { useKinetixReducedMotion } from '@/composables/useKinetixReducedMotion';

type Listener = (event: { matches: boolean }) => void;

function stubMedia(matches: boolean) {
    const listeners = new Set<Listener>();
    const media = {
        matches,
        addEventListener: (_: string, fn: Listener) => listeners.add(fn),
        removeEventListener: (_: string, fn: Listener) => listeners.delete(fn),
    };

    vi.stubGlobal(
        'matchMedia',
        vi.fn(() => media),
    );

    return {
        listeners,
        flip(next: boolean) {
            media.matches = next;
            listeners.forEach((fn) => fn({ matches: next }));
        },
    };
}

describe('useKinetixReducedMotion', () => {
    beforeEach(() => {
        delete pageProps.kinetix_config;
        document.documentElement.classList.remove('kx-reduce-motion');
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('follows the OS setting live', () => {
        const media = stubMedia(false);
        const scope = effectScope();
        const reduced = scope.run(() => useKinetixReducedMotion())!;

        expect(reduced.value).toBe(false);

        media.flip(true);
        expect(reduced.value).toBe(true);

        scope.stop();
    });

    it("follows the user's kx-reduce-motion preference class live", async () => {
        stubMedia(false);
        const scope = effectScope();
        const reduced = scope.run(() => useKinetixReducedMotion())!;

        document.documentElement.classList.add('kx-reduce-motion');
        // MutationObserver callbacks are microtasks.
        await nextTick();
        await Promise.resolve();

        expect(reduced.value).toBe(true);

        scope.stop();
    });

    it('honors the app-wide kinetix.motion = reduced config', () => {
        stubMedia(false);
        pageProps.kinetix_config = { motion: 'reduced' };

        const scope = effectScope();
        const reduced = scope.run(() => useKinetixReducedMotion())!;

        expect(reduced.value).toBe(true);

        scope.stop();
    });

    it('releases the shared media listener with the last consumer', () => {
        const media = stubMedia(false);
        const first = effectScope();
        const second = effectScope();

        first.run(() => useKinetixReducedMotion());
        second.run(() => useKinetixReducedMotion());
        expect(media.listeners.size).toBe(1);

        first.stop();
        expect(media.listeners.size).toBe(1);

        second.stop();
        expect(media.listeners.size).toBe(0);
    });
});
