import { shallowMount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { createI18n } from 'vue-i18n';

const startMock = vi.fn();
const state = {
    table: ref<Record<string, unknown> | null>(null),
    loading: ref(false),
};

vi.mock('@/composables/useKinetixReportRuns', () => ({
    useKinetixReportRuns: () => ({
        table: state.table,
        loading: state.loading,
        failed: ref(false),
        load: vi.fn(),
        start: startMock,
        stop: vi.fn(),
    }),
}));

import KinetixReportRunsTable from '@/components/KinetixReportRunsTable.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: {
        en: { kinetix: { report_runs_loading: 'Loading report runs…' } },
    },
});

const mountIt = () =>
    shallowMount(KinetixReportRunsTable, { global: { plugins: [i18n] } });

describe('KinetixReportRunsTable', () => {
    it('calls start() on mount', () => {
        mountIt();

        expect(startMock).toHaveBeenCalled();
    });

    it('shows a loading message while the table is not yet loaded', () => {
        state.table.value = null;
        state.loading.value = true;

        const w = mountIt();

        expect(w.text()).toContain('Loading report runs…');
        expect(w.findComponent({ name: 'KinetixTable' }).exists()).toBe(false);
    });

    it('renders KinetixTable once the table payload is loaded', () => {
        state.table.value = { records: [] };

        const w = mountIt();

        expect(w.findComponent({ name: 'KinetixTable' }).exists()).toBe(true);
    });
});
