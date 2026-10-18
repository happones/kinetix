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

import KinetixTeamAvatar from '@/components/KinetixTeamAvatar.vue';
import KinetixTeamIndicator from '@/components/KinetixTeamIndicator.vue';
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
        // The current team stays in the name: the aria-label replaces the text.
        expect(w.find('button').attributes('aria-label')).toBe(
            'Switch team: Acme',
        );
    });

    it('takes the host\'s own trigger and "New team" item', async () => {
        const w = mount(KinetixTeamSwitcher, {
            attachTo: document.body,
            global: { plugins: [i18n] },
            slots: {
                trigger: `<template #trigger="{ current }"><button class="host-trigger">{{ current.name }} ▾</button></template>`,
                create: '<div class="host-create">Create in a modal</div>',
            },
        });

        expect(w.find('.host-trigger').text()).toBe('Acme ▾');
        expect(w.find('button[aria-label="Switch team"]').exists()).toBe(false);

        const trigger = w.get('.host-trigger');
        await trigger.trigger('pointerdown', {
            button: 0,
            pointerType: 'mouse',
        });
        await trigger.trigger('click');
        await new Promise((resolve) => setTimeout(resolve));

        expect(document.body.querySelector('.host-create')?.textContent).toBe(
            'Create in a modal',
        );
        expect(document.body.textContent).not.toContain('New team');

        w.unmount();
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

describe("KinetixTeamSwitcher in the app's terms", () => {
    it('says what the app calls a team', async () => {
        const original = pageProps.kinetix_teams;
        pageProps.kinetix_teams = {
            ...original,
            labels: {
                switch: 'Switch account',
                select: 'Choose an account',
                create: 'New account',
            },
        } as typeof original;

        const w = mount(KinetixTeamSwitcher, {
            attachTo: document.body,
            global: { plugins: [i18n] },
        });
        await w.find('button').trigger('pointerdown', { button: 0 });
        await w.find('button').trigger('keydown', { key: 'Enter' });
        await new Promise((r) => setTimeout(r, 0));

        expect(w.find('button').attributes('aria-label')).toBe(
            'Switch account: Acme',
        );
        expect(document.body.textContent).toContain('New account');

        pageProps.kinetix_teams = original;
        w.unmount();
        document.body.innerHTML = '';
    });

    it('keeps only the team mark when a collapsed sidebar has no room for its name', () => {
        const w = mount(KinetixTeamSwitcher, {
            props: { variant: 'sidebar' },
            global: { plugins: [i18n] },
        });

        expect(w.find('button').classes()).toContain('w-full');
        expect(w.find('button span.truncate').classes()).toContain(
            'group-data-[collapsible=icon]:hidden',
        );
        expect(w.findComponent(KinetixTeamAvatar).exists()).toBe(true);
    });
});

describe('KinetixTeamAvatar', () => {
    const avatar = (team: Record<string, unknown>) =>
        mount(KinetixTeamAvatar, {
            props: { team: { name: 'Acme Coffee', initials: 'AC', ...team } },
        });

    it('shows the logo, and the initials once it fails to load', async () => {
        const w = avatar({ logo: '/logos/acme.png' });
        expect(w.find('img').attributes('src')).toBe('/logos/acme.png');

        await w.find('img').trigger('error');
        expect(w.find('img').exists()).toBe(false);
        expect(w.text()).toBe('AC');
    });

    it('falls back to the default logo icon when the logo fails to load', async () => {
        const w = avatar({ logo: '/logos/acme.png', logoIcon: 'building-2' });
        await w.find('img').trigger('error');

        expect(w.find('svg').exists()).toBe(true);
        expect(w.text()).not.toContain('AC');
    });

    it('shows the default logo icon for a team without a logo', () => {
        const w = avatar({ logoIcon: 'building-2' });

        expect(w.find('svg').exists()).toBe(true);
        expect(w.text()).not.toContain('AC');
    });

    it('leaves the color to the indicator', () => {
        expect(avatar({ accent: '#16a34a' }).html()).not.toContain('#16a34a');
    });
});

describe('KinetixTeamIndicator', () => {
    const indicator = (team: Record<string, unknown>) =>
        mount(KinetixTeamIndicator, {
            props: { team: { name: 'Acme', ...team } },
        });

    it('is a dot in the team type color', () => {
        const dot = indicator({ accent: '#16a34a' }).find('span span');

        expect(dot.classes()).toContain('rounded-full');
        expect(dot.attributes('style')).toContain('background-color: #16a34a');
    });

    it('shows the configured icon in place of the dot, in that color', () => {
        const w = indicator({ accent: '#16a34a', icon: 'store' });

        expect(w.find('svg').exists()).toBe(true);
        expect(w.find('svg').attributes('style')).toContain('color: #16a34a');
        expect(w.find('span span').exists()).toBe(false);
    });

    it('renders nothing without a color or an icon', () => {
        expect(
            indicator({}).find('[data-kinetix-team-indicator]').exists(),
        ).toBe(false);
    });
});

describe('KinetixTeamSwitcher indicator', () => {
    it('shows each team with its indicator, and the current one on the trigger', async () => {
        const original = pageProps.kinetix_teams;
        pageProps.kinetix_teams = {
            ...original,
            current: { id: 1, name: 'Acme', accent: '#16a34a' } as never,
            teams: [
                { ...original.teams[0], accent: '#16a34a' },
                { ...original.teams[1], accent: 'var(--color-info)' },
            ] as never,
        };

        const w = mount(KinetixTeamSwitcher, {
            props: { variant: 'sidebar' },
            attachTo: document.body,
            global: { plugins: [i18n] },
        });
        expect(w.find('button [data-kinetix-team-indicator]').exists()).toBe(
            true,
        );

        await w.find('button').trigger('pointerdown', { button: 0 });
        await w.find('button').trigger('keydown', { key: 'Enter' });
        await new Promise((resolve) => setTimeout(resolve, 0));
        const rows = document.querySelectorAll(
            '[role="menuitem"] [data-kinetix-team-indicator]',
        );
        expect(rows).toHaveLength(2);

        pageProps.kinetix_teams = original;
        w.unmount();
        document.body.innerHTML = '';
    });
});
