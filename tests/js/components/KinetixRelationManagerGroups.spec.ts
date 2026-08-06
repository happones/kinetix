import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: { kinetix_config: { route_prefix: '_kinetix' } },
    }),
    router: { get: vi.fn(), visit: vi.fn(), reload: vi.fn(), replace: vi.fn() },
    usePoll: () => ({ start: vi.fn(), stop: vi.fn() }),
    Link: {
        name: 'Link',
        template: '<a :href="href"><slot /></a>',
        props: ['href'],
    },
}));

import { router } from '@inertiajs/vue3';
import KinetixRelationManager from '@/components/KinetixRelationManager.vue';
import KinetixRelationManagers from '@/components/KinetixRelationManagers.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: { en: { kinetix: {} } },
});

const managerData = (overrides: Record<string, any> = {}) => ({
    title: 'Tags',
    relationship: 'tags',
    table: { records: [] },
    badge: null,
    badgeColor: null,
    ...overrides,
});

const mountHost = (managers: any[]) =>
    mount(KinetixRelationManagers, {
        props: { managers },
        global: { plugins: [i18n], stubs: { KinetixTable: true } },
    });

describe('grouped relation manager tabs', () => {
    afterEach(() => vi.clearAllMocks());

    it('managers sharing a group render as ONE tab with summed numeric badges', () => {
        const wrapper = mountHost([
            managerData({
                relationship: 'tags',
                title: 'Tags',
                group: 'Team Data',
                groupKey: 'team-data',
                badge: 2,
            }),
            managerData({
                relationship: 'tasks',
                title: 'Tasks',
                group: 'Team Data',
                groupKey: 'team-data',
                badge: 3,
            }),
            managerData({ relationship: 'notes', title: 'Notes' }),
        ]);

        const tabs = wrapper.findAll('[role="tab"]');
        expect(tabs).toHaveLength(2);
        expect(tabs[0].text()).toContain('Team Data');
        expect(tabs[0].text()).toContain('5');
        expect(tabs[1].text()).toContain('Notes');

        // The group panel stacks BOTH members, each with its own heading.
        const sections = wrapper.findAllComponents(KinetixRelationManager);
        expect(sections).toHaveLength(2);
        expect(wrapper.text()).toContain('Tags');
        expect(wrapper.text()).toContain('Tasks');
    });

    it('a collapsible section toggles its content and defers a collapsed lazy load', async () => {
        const reload = vi.mocked(router.reload);

        const wrapper = mount(KinetixRelationManager, {
            props: {
                manager: managerData({
                    table: null,
                    deferred: true,
                    collapsible: true,
                    collapsed: true,
                }) as any,
            },
            global: { plugins: [i18n], stubs: { KinetixTable: true } },
        });

        // Collapsed on mount: content hidden, NO load request yet.
        const toggle = wrapper.get('button[aria-expanded]');
        expect(toggle.attributes('aria-expanded')).toBe('false');
        expect(reload).not.toHaveBeenCalled();

        // Expanding loads the deferred manager.
        await toggle.trigger('click');
        expect(toggle.attributes('aria-expanded')).toBe('true');
        expect(reload).toHaveBeenCalledTimes(1);

        wrapper.unmount();
    });

    it('a lazy group member requests its GROUP key, not its own relation', () => {
        const reload = vi.mocked(router.reload);

        const wrapper = mount(KinetixRelationManager, {
            props: {
                manager: managerData({
                    relationship: 'tasks',
                    table: null,
                    deferred: true,
                    group: 'Team Data',
                    groupKey: 'team-data',
                }) as any,
            },
            global: { plugins: [i18n], stubs: { KinetixTable: true } },
        });

        expect(reload).toHaveBeenCalledWith(
            expect.objectContaining({ data: { relation: 'team-data' } }),
        );

        wrapper.unmount();
    });

    it('a ?relation= naming a group member activates the GROUP tab', () => {
        window.history.replaceState({}, '', '/?relation=tasks');

        const wrapper = mountHost([
            managerData({ relationship: 'notes', title: 'Notes' }),
            managerData({
                relationship: 'tags',
                title: 'Tags',
                group: 'Team Data',
                groupKey: 'team-data',
            }),
            managerData({
                relationship: 'tasks',
                title: 'Tasks',
                group: 'Team Data',
                groupKey: 'team-data',
            }),
        ]);

        const activeTab = wrapper.get('[role="tab"][aria-selected="true"]');
        expect(activeTab.text()).toContain('Team Data');

        window.history.replaceState({}, '', '/');
        wrapper.unmount();
    });
});
