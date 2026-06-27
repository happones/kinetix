import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

// poll: 0 → no interval, a single load on start.
const pageProps = {
    kinetix_config: { route_prefix: '_kinetix' },
    kinetix_queue: { enabled: true, poll: 0 },
};
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: pageProps }) }));

const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...a: unknown[]) => fetchMock(...a),
    kinetixRoutePrefix: () => '_kinetix',
}));

import KinetixQueueStats from '@/components/KinetixQueueStats.vue';
import { useKinetixQueue } from '@/composables/useKinetixQueue';

const snapshot = {
    horizon: true,
    status: 'running',
    throughput: 42,
    recentJobs: 1200,
    failedJobs: 3,
    failed: [
        {
            id: 'a1',
            connection: 'redis',
            queue: 'emails',
            name: 'SendEmail',
            failedAt: null,
        },
    ],
    queues: [
        { name: 'default', connection: null, size: 5, wait: 2 },
        { name: 'emails', connection: null, size: 1, wait: 0 },
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
                queue_title: 'Queue health',
                queue_throughput: 'Per minute',
                queue_recent: 'Recent jobs',
                queue_pending: 'Pending',
                queue_failed: 'Failed',
                queue_retry: 'Retry',
                remove: 'Remove',
                queue_wait: '{seconds}s wait',
                queue_unavailable: 'Queue metrics are unavailable.',
                queue_status_running: 'Running',
            },
        },
    },
});

beforeEach(() => fetchMock.mockReset());

const Harness = defineComponent({
    setup(_, { expose }) {
        expose(useKinetixQueue());
        return () => h('div');
    },
});

describe('useKinetixQueue', () => {
    it('loads a snapshot from the endpoint', async () => {
        fetchMock.mockResolvedValueOnce(snapshot);
        const vm = mount(Harness, { global: { plugins: [i18n] } }).vm as any;

        await vm.load();
        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/queue');
        expect(vm.snapshot.throughput).toBe(42);
    });

    it('flags failure when the request throws', async () => {
        fetchMock.mockRejectedValueOnce(new Error('nope'));
        const vm = mount(Harness, { global: { plugins: [i18n] } }).vm as any;

        await vm.load();
        expect(vm.failed).toBe(true);
    });

    it('retries and forgets failed jobs via the endpoints', async () => {
        fetchMock.mockResolvedValue(snapshot);
        const vm = mount(Harness, { global: { plugins: [i18n] } }).vm as any;

        await vm.retry('a1');
        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/queue/retry', {
            method: 'POST',
            body: { id: 'a1' },
        });

        await vm.forget('a1');
        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/queue/failed', {
            method: 'DELETE',
            body: { id: 'a1' },
        });
    });
});

describe('KinetixQueueStats', () => {
    it('renders metrics, status and per-queue rows', async () => {
        fetchMock.mockResolvedValueOnce(snapshot);
        const w = mount(KinetixQueueStats, { global: { plugins: [i18n] } });
        await flushPromises();

        expect(w.text()).toContain('Queue health');
        expect(w.text()).toContain('Running');
        expect(w.text()).toContain('42'); // throughput
        expect(w.text()).toContain('6'); // pending = 5 + 1
        expect(w.text()).toContain('default');
        expect(w.text()).toContain('emails');
    });

    it('lists failed jobs with retry / delete actions', async () => {
        fetchMock.mockResolvedValue(snapshot);
        const w = mount(KinetixQueueStats, { global: { plugins: [i18n] } });
        await flushPromises();

        expect(w.text()).toContain('SendEmail');
        expect(w.find('button[aria-label="Retry"]').exists()).toBe(true);
        expect(w.find('button[aria-label="Remove"]').exists()).toBe(true);

        fetchMock.mockResolvedValue(snapshot);
        await w.find('button[aria-label="Retry"]').trigger('click');
        expect(fetchMock).toHaveBeenCalledWith(
            '/_kinetix/queue/retry',
            expect.objectContaining({ method: 'POST' }),
        );
    });
});
