import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: {} }),
    router: { reload: vi.fn() },
}));
const fetchMock = vi.fn().mockResolvedValue({ status: 'success' });
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

import KinetixKanban from '@/components/KinetixKanban.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: {} } },
});

const kanban = {
    heading: 'Tasks',
    model: 'signed-descriptor',
    columns: [
        {
            key: 'todo',
            label: 'To Do',
            color: null,
            cards: [
                { id: 1, title: 'Card A', description: null },
                { id: 2, title: 'Card C', description: 'note' },
            ],
        },
        { key: 'doing', label: 'In Progress', color: null, cards: [] },
        { key: 'done', label: 'Done', color: null, cards: [] },
    ],
};

const mountIt = () =>
    mount(KinetixKanban, { props: { kanban }, global: { plugins: [i18n] } });

describe('KinetixKanban', () => {
    it('renders columns with their cards and counts', () => {
        const w = mountIt();
        expect(w.findAll('.flex.w-72, .w-72').length).toBeGreaterThanOrEqual(3);
        expect(w.text()).toContain('To Do');
        expect(w.text()).toContain('Card A');
        expect(w.text()).toContain('Card C');
        expect(w.findAll('article').length).toBe(2);
    });

    it('moves a card to another column and persists the new status', async () => {
        const w = mountIt();
        const card = w.findAll('article')[0]; // Card A in "todo"

        card.element.setAttribute('data-test', 'a');
        await card.trigger('dragstart');

        // Drop onto the third column ("done").
        const columns = w.findAll('[draggable]').length;
        expect(columns).toBeGreaterThan(0);

        const dropZones = w.findAll('.w-72');
        await dropZones[2].trigger('drop');
        await Promise.resolve();

        const call = fetchMock.mock.calls.find((c) =>
            String(c[0]).endsWith('/tables/kanban-move'),
        );
        expect(call).toBeTruthy();
        expect(call![1].body).toMatchObject({
            model: 'signed-descriptor',
            recordId: 1,
            status: 'done',
        });
    });

    it('cards carry the draggable-card semantics and keyboard instructions', () => {
        const wrapper = mountIt();

        const hint = wrapper.find('p.sr-only');
        expect(hint.exists()).toBe(true);
        expect(hint.attributes('id')).toMatch(/^kinetix-kanban-hint-/);

        const card = wrapper.get('[data-kanban-card]');
        expect(card.attributes('aria-roledescription')).toBeTruthy();
        expect(card.attributes('aria-describedby')).toBe(hint.attributes('id'));

        // Columns are labelled groups (name + count).
        const group = wrapper.get('[role="group"]');
        expect(group.attributes('aria-label')).toContain('(');
    });

    it('cards are focusable and the right arrow moves one column over', async () => {
        fetchMock.mockClear();
        const w = mountIt();
        const card = w.findAll('article')[0]; // Card A in "todo"

        expect(card.attributes('tabindex')).toBe('0');

        await card.trigger('keydown', { key: 'ArrowRight' });
        await Promise.resolve();

        const call = fetchMock.mock.calls.find((c) =>
            String(c[0]).endsWith('/tables/kanban-move'),
        );
        expect(call).toBeTruthy();
        expect(call![1].body).toMatchObject({
            recordId: 1,
            status: 'doing',
        });
    });

    it('the left arrow on the first column is a no-op', async () => {
        fetchMock.mockClear();
        const w = mountIt();
        const card = w.findAll('article')[0];

        await card.trigger('keydown', { key: 'ArrowLeft' });
        await Promise.resolve();

        expect(
            fetchMock.mock.calls.find((c) =>
                String(c[0]).endsWith('/tables/kanban-move'),
            ),
        ).toBeUndefined();
    });
});
