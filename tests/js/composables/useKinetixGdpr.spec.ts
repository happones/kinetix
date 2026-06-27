import { beforeEach, describe, expect, it, vi } from 'vitest';

const fetchMock = vi.fn();

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import { useKinetixGdpr } from '@/composables/useKinetixGdpr';

describe('useKinetixGdpr', () => {
    beforeEach(() => fetchMock.mockReset());

    it('posts an export request and toggles the exporting flag', async () => {
        fetchMock.mockResolvedValue({ status: 'queued' });

        const gdpr = useKinetixGdpr();
        const promise = gdpr.exportData();
        expect(gdpr.exporting.value).toBe(true);
        await promise;

        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/gdpr/export', {
            method: 'POST',
        });
        expect(gdpr.exporting.value).toBe(false);
    });

    it('posts the password when deleting and returns the redirect', async () => {
        fetchMock.mockResolvedValue({ status: 'success', redirect: '/bye' });

        const result = await useKinetixGdpr().deleteAccount('hunter2');

        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/gdpr/delete', {
            method: 'POST',
            body: { password: 'hunter2' },
        });
        expect(result?.redirect).toBe('/bye');
    });

    it('omits the password body when none is given', async () => {
        fetchMock.mockResolvedValue({ status: 'success', redirect: '/' });

        await useKinetixGdpr().deleteAccount();

        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/gdpr/delete', {
            method: 'POST',
            body: {},
        });
    });
});
