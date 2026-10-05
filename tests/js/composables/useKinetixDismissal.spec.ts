import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { effectScope, ref } from 'vue';

const pageProps: Record<string, unknown> = { auth: { user: { id: 7 } } };
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: pageProps }) }));

import {
    useKinetixDismissal,
    useKinetixDismissalStore,
} from '@/composables/useKinetixDismissal';

function inScope<T>(fn: () => T): { value: T; stop: () => void } {
    const scope = effectScope();
    const value = scope.run(fn)!;

    return { value, stop: () => scope.stop() };
}

describe('useKinetixDismissalStore', () => {
    beforeEach(() => {
        sessionStorage.clear();
        localStorage.clear();
        pageProps.auth = { user: { id: 7 } };
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('remembers a close per scope and forgets it on demand', () => {
        const store = useKinetixDismissalStore();

        store.remember('tour-hint', 'session');
        store.remember('beta-banner', 'device');

        expect(store.isDismissed('tour-hint')).toBe(true);
        expect(store.isDismissed('beta-banner')).toBe(true);
        expect(
            sessionStorage.getItem('kinetix.dismissed:7:tour-hint'),
        ).not.toBeNull();
        expect(
            localStorage.getItem('kinetix.dismissed:7:beta-banner'),
        ).not.toBeNull();

        store.forget('beta-banner');
        expect(store.isDismissed('beta-banner')).toBe(false);
    });

    it('lets a timed close lapse on its own', () => {
        vi.useFakeTimers();
        vi.setSystemTime(new Date('2026-10-05T10:00:00Z'));
        const store = useKinetixDismissalStore();

        store.remember('weekly-digest', 'device', 60_000);
        expect(store.isDismissed('weekly-digest')).toBe(true);

        vi.setSystemTime(new Date('2026-10-05T10:01:01Z'));
        expect(store.isDismissed('weekly-digest')).toBe(false);
        // A lapsed entry is cleaned up, not kept around forever.
        expect(
            localStorage.getItem('kinetix.dismissed:7:weekly-digest'),
        ).toBeNull();
    });

    it('keeps two accounts on one browser apart', () => {
        useKinetixDismissalStore().remember('promo', 'device');

        pageProps.auth = { user: { id: 8 } };
        expect(useKinetixDismissalStore().isDismissed('promo')).toBe(false);
    });

    it('falls back to tab memory when the browser refuses storage', () => {
        const setItem = vi
            .spyOn(Storage.prototype, 'setItem')
            .mockImplementation(() => {
                throw new Error('QuotaExceededError');
            });
        const store = useKinetixDismissalStore();

        store.remember('private-mode', 'session');
        expect(store.isDismissed('private-mode')).toBe(true);

        setItem.mockRestore();
        store.forget('private-mode');
    });
});

describe('useKinetixDismissal', () => {
    beforeEach(() => {
        sessionStorage.clear();
        localStorage.clear();
    });

    it('`hide` closes this instance only', async () => {
        const first = inScope(() => useKinetixDismissal('hint'));
        await first.value.dismiss('hide');
        expect(first.value.dismissed.value).toBe(true);

        const second = inScope(() => useKinetixDismissal('hint'));
        expect(second.value.dismissed.value).toBe(false);

        first.stop();
        second.stop();
    });

    it('`session` and `device` survive a remount', async () => {
        const first = inScope(() =>
            useKinetixDismissal('banner', { mode: 'device' }),
        );
        await first.value.dismiss();
        first.stop();

        const second = inScope(() => useKinetixDismissal('banner'));
        expect(second.value.dismissed.value).toBe(true);

        second.value.restore();
        expect(second.value.dismissed.value).toBe(false);
        second.stop();
    });

    it('without a key every mode behaves like hide', async () => {
        const first = inScope(() => useKinetixDismissal(null));
        await first.value.dismiss('device');
        expect(first.value.dismissed.value).toBe(true);
        expect(localStorage.length).toBe(0);
        first.stop();
    });

    it('`permanent` persists through the callback and guards the tab', async () => {
        const persist = vi.fn().mockResolvedValue(undefined);
        const first = inScope(() =>
            useKinetixDismissal('profile', { mode: 'permanent', persist }),
        );

        await first.value.dismiss();

        expect(persist).toHaveBeenCalledWith('profile');
        // A page restored from history still carries the old payload.
        expect(useKinetixDismissalStore().isDismissed('profile')).toBe(true);
        first.stop();
    });

    it('re-opens when the permanent persist rejects', async () => {
        const persist = vi.fn().mockRejectedValue(new Error('422'));
        const first = inScope(() =>
            useKinetixDismissal('profile', { mode: 'permanent', persist }),
        );

        await expect(first.value.dismiss()).rejects.toThrow('422');
        expect(first.value.dismissed.value).toBe(false);
        expect(useKinetixDismissalStore().isDismissed('profile')).toBe(false);
        first.stop();
    });

    it('`permanent` without a persist callback degrades to device', async () => {
        const first = inScope(() =>
            useKinetixDismissal('no-server', { mode: 'permanent' }),
        );
        await first.value.dismiss();

        expect(
            localStorage.getItem('kinetix.dismissed:7:no-server'),
        ).not.toBeNull();
        first.stop();
    });

    it('follows a close made in another tab', () => {
        const key = ref('shared');
        const first = inScope(() => useKinetixDismissal(key));
        expect(first.value.dismissed.value).toBe(false);

        // The other tab writes, then the browser fires `storage` here.
        useKinetixDismissalStore().remember('shared', 'device');
        window.dispatchEvent(
            new StorageEvent('storage', { key: 'kinetix.dismissed:7:shared' }),
        );

        expect(first.value.dismissed.value).toBe(true);
        first.stop();
    });
});
