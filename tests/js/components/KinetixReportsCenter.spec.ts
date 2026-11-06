import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';
import KinetixReportsCenter from '@/components/KinetixReportsCenter.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: {
        en: {
            kinetix: {
                report_launcher_title: 'Reports',
                report_runs_title: 'Report Runs',
                report_schedules_title: 'Scheduled Reports',
            },
        },
    },
});

const mountIt = () =>
    mount(KinetixReportsCenter, {
        global: {
            plugins: [i18n],
            stubs: {
                KinetixReportLauncher: true,
                KinetixReportRunsTable: true,
                KinetixReportSchedules: true,
            },
        },
    });

describe('KinetixReportsCenter', () => {
    it('renders exactly three accessible tab triggers, one per view', () => {
        const w = mountIt();

        const tabs = w.findAll('[role="tab"]');

        expect(tabs).toHaveLength(3);
        expect(tabs.map((el) => el.text())).toEqual([
            'Reports',
            'Report Runs',
            'Scheduled Reports',
        ]);
    });

    it('defaults to the launcher tab', () => {
        const w = mountIt();

        expect(
            w.findComponent({ name: 'KinetixReportLauncher' }).exists(),
        ).toBe(true);
    });

    it('renders exactly three accessible tab triggers', () => {
        const w = mountIt();

        expect(w.findAll('[role="tab"]')).toHaveLength(3);
    });

    it('switches to the runs view when its tab is activated', async () => {
        const w = mountIt();
        const runsTrigger = w
            .findAll('[role="tab"]')
            .find((el) => el.text() === 'Report Runs')!;

        expect(runsTrigger.attributes('aria-selected')).toBe('false');
        expect(
            w.findComponent({ name: 'KinetixReportRunsTable' }).exists(),
        ).toBe(false);

        // Reka's TabsTrigger activates on mousedown, not click.
        await runsTrigger.trigger('mousedown', { button: 0 });

        expect(runsTrigger.attributes('aria-selected')).toBe('true');
        expect(
            w.findComponent({ name: 'KinetixReportRunsTable' }).exists(),
        ).toBe(true);
        expect(
            w.findComponent({ name: 'KinetixReportLauncher' }).exists(),
        ).toBe(false);
    });
});
