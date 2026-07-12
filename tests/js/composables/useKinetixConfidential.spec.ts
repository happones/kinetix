import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const fetchMock = vi.fn();
const reloadMock = vi.fn();
const state = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: state.props }),
    router: { reload: (...args: unknown[]) => reloadMock(...args) },
}));

vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import {
    requestConfidentialUnlock,
    useKinetixConfidential,
} from '@/composables/useKinetixConfidential';

describe('useKinetixConfidential', () => {
    beforeEach(() => {
        fetchMock.mockReset();
        reloadMock.mockReset();
        state.props = {};
    });

    afterEach(() => {
        vi.useRealTimers();
    });

    it('defaults to disabled and locked when the shared prop is absent', () => {
        const { config, isUnlocked, remainingSeconds } =
            useKinetixConfidential();

        expect(config.value).toEqual({
            enabled: false,
            ttlMinutes: 5,
            unlockedUntil: null,
        });
        expect(isUnlocked.value).toBe(false);
        expect(remainingSeconds.value).toBe(0);
    });

    it('is unlocked while unlockedUntil is in the future', () => {
        state.props = {
            kinetix_confidential: {
                enabled: true,
                ttlMinutes: 5,
                unlockedUntil: new Date(Date.now() + 60_000).toISOString(),
            },
        };

        const { isUnlocked, remainingSeconds } = useKinetixConfidential();

        expect(isUnlocked.value).toBe(true);
        expect(remainingSeconds.value).toBeGreaterThan(0);
        expect(remainingSeconds.value).toBeLessThanOrEqual(60);
    });

    it('is locked once unlockedUntil is in the past', () => {
        state.props = {
            kinetix_confidential: {
                enabled: true,
                ttlMinutes: 5,
                unlockedUntil: new Date(Date.now() - 1000).toISOString(),
            },
        };

        const { isUnlocked, remainingSeconds } = useKinetixConfidential();

        expect(isUnlocked.value).toBe(false);
        expect(remainingSeconds.value).toBe(0);
    });

    it('unlock() posts the password and reloads the page', async () => {
        fetchMock.mockResolvedValue({ unlocked: true });

        await useKinetixConfidential().unlock('secret');

        expect(fetchMock).toHaveBeenCalledWith(
            '/_kinetix/confidential/unlock',
            {
                method: 'POST',
                body: { password: 'secret' },
            },
        );
        expect(reloadMock).toHaveBeenCalledTimes(1);
    });

    it('unlock() propagates a rejected request without reloading', async () => {
        fetchMock.mockRejectedValue(new Error('Incorrect password.'));

        await expect(useKinetixConfidential().unlock('wrong')).rejects.toThrow(
            'Incorrect password.',
        );
        expect(reloadMock).not.toHaveBeenCalled();
    });

    it('lock() posts to the lock endpoint and reloads the page', async () => {
        fetchMock.mockResolvedValue({ unlocked: false });

        await useKinetixConfidential().lock();

        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/confidential/lock', {
            method: 'POST',
        });
        expect(reloadMock).toHaveBeenCalledTimes(1);
    });

    it('requestConfidentialUnlock() opens the shared dialog state', () => {
        const { dialogOpen } = useKinetixConfidential();
        expect(dialogOpen.value).toBe(false);

        requestConfidentialUnlock();

        expect(dialogOpen.value).toBe(true);

        // Reset the module-level singleton so it doesn't leak into other tests.
        dialogOpen.value = false;
    });
});
