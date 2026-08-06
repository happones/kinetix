import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import KinetixRelationManagers from '@/components/KinetixRelationManagers.vue';
import type { KinetixRelationManagerData } from '@/types/kinetix';
import { i18n } from './i18n';

/** The real KinetixTable is heavy — stub it with its query prefix as text. */
const TableStub = defineComponent({
    props: { table: { type: Object, required: true } },
    setup(props) {
        return () =>
            h(
                'div',
                { 'data-test': 'table' },
                String(props.table.queryPrefix ?? ''),
            );
    },
});

const manager = (
    relationship: string,
    overrides: Partial<KinetixRelationManagerData> = {},
): KinetixRelationManagerData =>
    ({
        title: relationship.charAt(0).toUpperCase() + relationship.slice(1),
        relationship,
        table: { queryPrefix: `${relationship}_` },
        ...overrides,
    }) as KinetixRelationManagerData;

const mountManagers = (
    managers: KinetixRelationManagerData[],
    props: Record<string, unknown> = {},
) =>
    mount(KinetixRelationManagers, {
        props: { managers, ...props },
        global: {
            plugins: [i18n],
            stubs: { KinetixTable: TableStub },
        },
    });

describe('KinetixRelationManagers', () => {
    it('activates the tab named by ?relation= in the URL', () => {
        window.history.replaceState(null, '', '/records/1/edit?relation=tags');

        const wrapper = mountManagers([
            manager('posts'),
            manager('comments'),
            manager('tags'),
        ]);

        const activeTab = wrapper
            .findAll('[role="tab"]')
            .find((t) => t.attributes('aria-selected') === 'true');
        expect(activeTab?.text()).toContain('Tags');
        expect(wrapper.get('[data-test="table"]').text()).toBe('tags_');

        wrapper.unmount();
        window.history.replaceState(null, '', '/');
    });

    it('writes the selected tab into the URL so reloads and back() land on it', async () => {
        window.history.replaceState(null, '', '/records/1/edit?posts_page=2');

        const wrapper = mountManagers([manager('posts'), manager('comments')]);

        await wrapper
            .findAll('[role="tab"]')[1]
            .trigger('mousedown', { button: 0 });

        const params = new URLSearchParams(window.location.search);
        expect(params.get('relation')).toBe('comments');
        // Foreign table params survive the client-side replace.
        expect(params.get('posts_page')).toBe('2');

        wrapper.unmount();
        window.history.replaceState(null, '', '/');
    });

    it('ignores an unknown ?relation= value and falls back to the first tab', () => {
        window.history.replaceState(null, '', '/records/1/edit?relation=nope');

        const wrapper = mountManagers([manager('posts'), manager('comments')]);

        expect(wrapper.get('[data-test="table"]').text()).toBe('posts_');

        wrapper.unmount();
        window.history.replaceState(null, '', '/');
    });

    it('renders a single manager as a plain section (no tabs)', () => {
        const wrapper = mountManagers([manager('posts')]);

        expect(wrapper.find('[role="tablist"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('Posts');
        expect(wrapper.get('[data-test="table"]').text()).toBe('posts_');
    });

    it('auto-tabs multiple managers and renders only the active table', async () => {
        const wrapper = mountManagers([
            manager('posts', { badge: 3 }),
            manager('comments', { badge: 12, badgeColor: 'primary' }),
            manager('tags'),
        ]);

        const tabs = wrapper.findAll('[role="tab"]');
        expect(tabs).toHaveLength(3);

        // Badges render on the tabs.
        expect(tabs[0].text()).toContain('3');
        expect(tabs[1].text()).toContain('12');

        // Only the first (active) manager's table is mounted.
        expect(wrapper.findAll('[data-test="table"]')).toHaveLength(1);
        expect(wrapper.get('[data-test="table"]').text()).toBe('posts_');

        await tabs[1].trigger('mousedown', { button: 0 });

        expect(wrapper.get('[data-test="table"]').text()).toBe('comments_');
        expect(tabs[1].attributes('aria-selected')).toBe('true');
    });

    it('stacks every manager when tabs are disabled', () => {
        const wrapper = mountManagers([manager('posts'), manager('comments')], {
            tabs: false,
        });

        expect(wrapper.find('[role="tablist"]').exists()).toBe(false);
        expect(wrapper.findAll('[data-test="table"]')).toHaveLength(2);
    });

    it('renders nothing for an empty manager list', () => {
        const wrapper = mountManagers([]);

        expect(wrapper.find('section').exists()).toBe(false);
        expect(wrapper.find('[role="tablist"]').exists()).toBe(false);
    });

    it('opens the attach modal only for the manager the event targets', async () => {
        // The modal loads attachable options on open — stub the transport.
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                status: 200,
                text: () => Promise.resolve(JSON.stringify({ options: [] })),
            } as unknown as Response),
        );
        document.cookie = 'XSRF-TOKEN=test-token';

        const wrapper = mount(KinetixRelationManagers, {
            attachTo: document.body,
            props: {
                managers: [
                    manager('posts', { descriptor: 'signed-posts' }),
                    manager('tags', { descriptor: 'signed-tags' }),
                ],
            },
            global: {
                plugins: [i18n],
                stubs: { KinetixTable: TableStub },
            },
        });

        // Active tab is "posts": an event for ANOTHER relationship must not open it.
        window.dispatchEvent(
            new CustomEvent('kinetix:open-attach', {
                detail: { relationship: 'tags' },
            }),
        );
        await wrapper.vm.$nextTick();
        expect(document.querySelector('[role="dialog"]')).toBeNull();

        window.dispatchEvent(
            new CustomEvent('kinetix:open-attach', {
                detail: { relationship: 'posts' },
            }),
        );
        await wrapper.vm.$nextTick();

        expect(document.querySelector('[role="dialog"]')).not.toBeNull();
        expect(document.body.textContent).toContain('Attach');

        wrapper.unmount();
        document.body.innerHTML = '';
        vi.unstubAllGlobals();
    });
});
