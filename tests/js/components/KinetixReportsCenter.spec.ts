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
    it('renders a tab trigger for each of the three views', () => {
        const w = mountIt();

        expect(w.text()).toContain('Reports');
        expect(w.text()).toContain('Report Runs');
        expect(w.text()).toContain('Scheduled Reports');
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

    it('does not throw when a tab trigger is clicked', async () => {
        const w = mountIt();

        const runsTrigger = w
            .findAll('[role="tab"]')
            .find((el) => el.text() === 'Report Runs');

        await expect(runsTrigger?.trigger('click')).resolves.not.toThrow();
    });
});
