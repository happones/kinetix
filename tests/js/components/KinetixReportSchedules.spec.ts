import { shallowMount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { createI18n } from 'vue-i18n';

const startMock = vi.fn();
const createMock = vi.fn();
const loadTypesMock = vi.fn();
const state = {
    table: ref<Record<string, unknown> | null>(null),
    types: ref<Array<{ token: string; label: string }>>([]),
};

vi.mock('@/composables/useKinetixReportSchedules', () => ({
    useKinetixReportSchedules: () => ({
        table: state.table,
        loading: ref(false),
        failed: ref(false),
        load: vi.fn(),
        start: startMock,
        stop: vi.fn(),
        create: createMock,
    }),
}));

vi.mock('@/composables/useKinetixReportTypes', () => ({
    useKinetixReportTypes: () => ({
        types: state.types,
        loading: ref(false),
        failed: ref(false),
        load: loadTypesMock,
        launch: vi.fn(),
    }),
}));

vi.mock('vue-sonner', () => ({
    toast: { success: vi.fn(), error: vi.fn() },
}));

import KinetixReportSchedules from '@/components/KinetixReportSchedules.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: {
        en: {
            kinetix: {
                report_runs_report_column: 'Report',
                report_schedule_frequency: 'Frequency',
                report_schedule_create: 'Schedule',
                report_schedule_created: 'Report schedule created.',
                report_schedule_create_failed:
                    'Could not create the report schedule.',
                report_launcher_title: 'Reports',
                report_runs_loading: 'Loading report runs…',
            },
        },
    },
});

const mountIt = () =>
    shallowMount(KinetixReportSchedules, { global: { plugins: [i18n] } });

describe('KinetixReportSchedules', () => {
    it('calls start() and loads report types on mount', () => {
        mountIt();

        expect(startMock).toHaveBeenCalled();
        expect(loadTypesMock).toHaveBeenCalled();
    });

    it('renders KinetixTable once the table payload is loaded', () => {
        state.table.value = { records: [] };

        const w = mountIt();

        expect(w.findComponent({ name: 'KinetixTable' }).exists()).toBe(true);
    });

    it('disables the create button until a report is selected', () => {
        const w = mountIt();

        const button = w.findAll('button').find((b) => b.text() === 'Schedule');

        expect(button?.attributes('disabled')).toBeDefined();
    });
});
