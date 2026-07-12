import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { createI18n } from 'vue-i18n';

const loadMock = vi.fn();
const launchMock = vi.fn();
const state = { types: ref([] as unknown[]) };

vi.mock('@/composables/useKinetixReportTypes', () => ({
    useKinetixReportTypes: () => ({
        types: state.types,
        loading: ref(false),
        failed: ref(false),
        load: loadMock,
        launch: launchMock,
    }),
}));

vi.mock('vue-sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

import KinetixReportLauncher from '@/components/KinetixReportLauncher.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: {
        en: {
            kinetix: {
                run_now: 'Run now',
                report_launcher_empty: 'No report types are registered yet.',
            },
        },
    },
});

const mountIt = () =>
    mount(KinetixReportLauncher, { global: { plugins: [i18n] } });

describe('KinetixReportLauncher', () => {
    it('calls load() on mount', () => {
        mountIt();

        expect(loadMock).toHaveBeenCalled();
    });

    it('renders a card per report type with its label/description/format', () => {
        state.types.value = [
            {
                token: 'tok1',
                label: 'Orders',
                description: 'Export all orders',
                format: 'csv',
            },
        ];

        const w = mountIt();

        expect(w.text()).toContain('Orders');
        expect(w.text()).toContain('Export all orders');
        expect(w.text()).toContain('csv');
        expect(w.text()).toContain('Run now');
    });

    it('shows the empty state when there are no report types', () => {
        state.types.value = [];

        const w = mountIt();

        expect(w.text()).toContain('No report types are registered yet.');
    });

    it('clicking "Run now" launches the report by token', async () => {
        state.types.value = [
            {
                token: 'tok1',
                label: 'Orders',
                description: null,
                format: 'csv',
            },
        ];
        launchMock.mockResolvedValue({ status: 'queued', run_id: 1 });

        const w = mountIt();
        await w.find('button').trigger('click');

        expect(launchMock).toHaveBeenCalledWith('tok1');
    });
});
