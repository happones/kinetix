import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

const pageProps = {
    kinetix_config: { route_prefix: '_kinetix' },
    kinetix_health: { enabled: true, poll: 0 },
};
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: pageProps }) }));

const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...a: unknown[]) => fetchMock(...a),
    kinetixRoutePrefix: () => '_kinetix',
}));

import KinetixHealthStatus from '@/components/KinetixHealthStatus.vue';
import { useKinetixHealth } from '@/composables/useKinetixHealth';

const snapshot = {
    available: true,
    status: 'warning',
    checkedAt: '2026-06-27T10:00:00Z',
    checks: [
        { name: 'database', label: 'Database', status: 'ok', message: null },
        {
            name: 'disk',
            label: 'Used Disk Space',
            status: 'warning',
            message: 'Disk usage 85%',
        },
    ],
};

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: {
        en: {
            kinetix: {
                health_title: 'System health',
                health_unavailable: 'Health checks are unavailable.',
                health_status_ok: 'Healthy',
                health_status_warning: 'Warning',
                health_status_failed: 'Failing',
            },
        },
    },
});

beforeEach(() => fetchMock.mockReset());

const Harness = defineComponent({
    setup(_, { expose }) {
        expose(useKinetixHealth());
        return () => h('div');
    },
});

describe('useKinetixHealth', () => {
    it('loads a snapshot from the endpoint', async () => {
        fetchMock.mockResolvedValueOnce(snapshot);
        const vm = mount(Harness, { global: { plugins: [i18n] } }).vm as any;

        await vm.load();
        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/health');
        expect(vm.snapshot.status).toBe('warning');
    });
});

describe('KinetixHealthStatus', () => {
    it('renders the overall status and per-check rows', async () => {
        fetchMock.mockResolvedValueOnce(snapshot);
        const w = mount(KinetixHealthStatus, { global: { plugins: [i18n] } });
        await flushPromises();

        expect(w.text()).toContain('System health');
        expect(w.text()).toContain('Warning');
        expect(w.text()).toContain('Database');
        expect(w.text()).toContain('Used Disk Space');
        expect(w.text()).toContain('Disk usage 85%');
    });

    it('shows the unavailable message when health is off', async () => {
        fetchMock.mockResolvedValueOnce({
            available: false,
            status: null,
            checkedAt: null,
            checks: [],
        });
        const w = mount(KinetixHealthStatus, { global: { plugins: [i18n] } });
        await flushPromises();

        expect(w.text()).toContain('Health checks are unavailable.');
    });
});
