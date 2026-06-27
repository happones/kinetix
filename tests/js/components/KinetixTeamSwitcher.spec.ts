import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

const visitMock = vi.fn();
const pageProps = {
    kinetix_teams: {
        enabled: true,
        current: { id: 1, name: 'Acme' },
        createUrl: '/teams/create',
        teams: [
            { id: 1, name: 'Acme', url: '/teams/acme/switch', current: true },
            {
                id: 2,
                name: 'Globex',
                url: '/teams/globex/switch',
                current: false,
            },
        ],
    },
};

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageProps }),
    router: { visit: (...a: unknown[]) => visitMock(...a) },
}));

import KinetixTeamSwitcher from '@/components/KinetixTeamSwitcher.vue';
import { useKinetixTeams } from '@/composables/useKinetixTeams';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: {
        en: {
            kinetix: {
                teams_switch: 'Switch team',
                teams_select: 'Select a team',
                teams_new: 'New team',
            },
        },
    },
});

beforeEach(() => visitMock.mockReset());

describe('KinetixTeamSwitcher', () => {
    it('shows the current team on the trigger', () => {
        const w = mount(KinetixTeamSwitcher, { global: { plugins: [i18n] } });
        expect(w.text()).toContain('Acme');
        expect(w.find('button').attributes('aria-label')).toBe('Switch team');
    });
});

const Harness = defineComponent({
    setup(_, { expose }) {
        expose(useKinetixTeams());
        return () => h('div');
    },
});

const mountComposable = () => mount(Harness, { global: { plugins: [i18n] } });

describe('useKinetixTeams', () => {
    it('exposes teams and the current team', () => {
        const vm = mountComposable().vm as any;
        expect(vm.teams).toHaveLength(2);
        expect(vm.current.name).toBe('Acme');
        expect(vm.createUrl).toBe('/teams/create');
    });

    it('visits the switch URL for another team', () => {
        const vm = mountComposable().vm as any;
        vm.switchTeam(vm.teams[1]);
        expect(visitMock).toHaveBeenCalledWith('/teams/globex/switch');
    });

    it('does not switch to the already-current team', () => {
        const vm = mountComposable().vm as any;
        vm.switchTeam(vm.teams[0]);
        expect(visitMock).not.toHaveBeenCalled();
    });
});
