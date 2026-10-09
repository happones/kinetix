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
        expect(w.findAll('[data-kanban-column]').length).toBeGreaterThanOrEqual(
            3,
        );
        expect(w.text()).toContain('To Do');
        expect(w.text()).toContain('Card A');
        expect(w.text()).toContain('Card C');
        expect(w.findAll('article').length).toBe(2);
    });

    it('clips its columns inside the board scroller, never the page', () => {
        const w = mountIt();

        // Positioned: a card's absolutely positioned sr-only text takes the
        // scroller as its containing block, so off-screen columns stay
        // clipped instead of widening the whole page on a phone.
        const board = w.find('.overflow-x-auto');
        expect(board.classes()).toContain('relative');
        expect(w.find('[data-kanban-column]').classes()).toContain(
            'w-[min(18rem,85vw)]',
        );
    });

    it('moves a card to another column and persists the new status', async () => {
        const w = mountIt();
        const card = w.findAll('article')[0]; // Card A in "todo"

        card.element.setAttribute('data-test', 'a');
        await card.trigger('dragstart');

        // Drop onto the third column ("done").
        const columns = w.findAll('[draggable]').length;
        expect(columns).toBeGreaterThan(0);

        const dropZones = w.findAll('[data-kanban-column]');
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

    it('resyncs the board when the kanban prop is replaced (Inertia reload)', async () => {
        const w = mountIt();
        expect(w.findAll('article').length).toBe(2);

        // Simulate an Inertia partial reload after a modal create: the server
        // ships a brand-new kanban object with an extra card in "doing".
        await w.setProps({
            kanban: {
                ...kanban,
                columns: [
                    kanban.columns[0],
                    {
                        key: 'doing',
                        label: 'In Progress',
                        color: null,
                        cards: [{ id: 3, title: 'Card B', description: null }],
                    },
                    kanban.columns[2],
                ],
            },
        });

        expect(w.findAll('article').length).toBe(3);
        expect(w.text()).toContain('Card B');
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

describe('KinetixKanban card clicks and drag feedback', () => {
    it('emits card-click with the card and its column on click and Enter', async () => {
        const w = mountIt();
        const card = w.findAll('article')[0]; // Card A in "todo"

        await card.trigger('click');
        await card.trigger('keydown', { key: 'Enter' });

        const emitted = w.emitted('card-click');
        expect(emitted).toHaveLength(2);
        expect(emitted![0][0]).toMatchObject({ id: 1, title: 'Card A' });
        expect(emitted![0][1]).toBe('todo');
    });

    it('previews the drop with a ghost card in the hovered column', async () => {
        const w = mountIt();
        const card = w.findAll('article')[0]; // Card A in "todo"

        await card.trigger('dragstart');

        const target = w.get('[data-kanban-column="done"]');
        await target.trigger('dragenter');

        const ghost = target.find('.kx-drop-ghost');
        expect(ghost.exists()).toBe(true);
        expect(ghost.text()).toContain('Card A');

        // Never in the card's own column — dropping there is a no-op.
        const source = w.get('[data-kanban-column="todo"]');
        await source.trigger('dragenter');
        expect(source.find('.kx-drop-ghost').exists()).toBe(false);

        // And it disappears when the drag ends.
        await card.trigger('dragend');
        expect(w.find('.kx-drop-ghost').exists()).toBe(false);
    });

    it('dims the source card and highlights the hovered column while dragging', async () => {
        const w = mountIt();
        const card = w.findAll('article')[0];

        await card.trigger('dragstart');
        expect(card.classes()).toContain('opacity-40');

        const target = w.get('[data-kanban-column="done"]');
        await target.trigger('dragenter');
        expect(target.classes()).toContain('ring-2');

        await target.trigger('dragleave');
        expect(target.classes()).not.toContain('ring-2');

        await card.trigger('dragend');
        expect(card.classes()).not.toContain('opacity-40');
    });

    // The platform can take a touch drag over (pointercancel); the card used
    // to stay dimmed as if still in flight.
    it('a cancelled touch drag leaves nothing in flight', async () => {
        vi.useFakeTimers();
        const w = mountIt();
        const card = w.findAll('article')[0];
        const touch = (type: string) =>
            new PointerEvent(type, {
                bubbles: true,
                cancelable: true,
                pointerType: 'touch',
                isPrimary: true,
            });

        card.element.dispatchEvent(touch('pointerdown'));
        vi.advanceTimersByTime(250);
        await w.vm.$nextTick();
        expect(card.classes()).toContain('opacity-40');

        window.dispatchEvent(touch('pointercancel'));
        await w.vm.$nextTick();

        expect(card.classes()).not.toContain('opacity-40');
        expect(fetchMock).not.toHaveBeenCalled();
        vi.useRealTimers();
        w.unmount();
    });
});

describe('KinetixKanban reorderable boards', () => {
    const board = () => ({
        heading: null,
        model: 'signed-descriptor',
        reorderable: true,
        columns: [
            {
                key: 'todo',
                label: 'To Do',
                color: null,
                cards: [
                    { id: 1, title: 'Card A', description: null },
                    { id: 2, title: 'Card C', description: null },
                ],
            },
            {
                key: 'doing',
                label: 'In Progress',
                color: null,
                cards: [
                    { id: 3, title: 'Card X', description: null },
                    { id: 4, title: 'Card Y', description: null },
                ],
            },
        ],
    });

    const mountBoard = (data = board()) =>
        mount(KinetixKanban, {
            props: { kanban: data },
            global: { plugins: [i18n] },
            attachTo: document.body,
        });

    // happy-dom lays nothing out: give each card an 80px box, 100px apart.
    const layOut = (w: ReturnType<typeof mountBoard>): void => {
        for (const column of w.findAll('[data-kanban-column]')) {
            column.findAll('article').forEach((card, i) => {
                card.element.getBoundingClientRect = () =>
                    ({ top: i * 100, height: 80 }) as DOMRect;
            });
        }
    };

    const lastMove = () =>
        fetchMock.mock.calls
            .filter((c) => String(c[0]).endsWith('/tables/kanban-move'))
            .at(-1);

    const titles = (w: ReturnType<typeof mountBoard>, key: string) =>
        w
            .get(`[data-kanban-column="${key}"]`)
            .findAll('article')
            .map((a) => a.find('p').text());

    it('drops a card at the slot under the pointer and sends the column order', async () => {
        fetchMock.mockClear();
        const w = mountBoard();
        layOut(w);

        await w.findAll('article')[0].trigger('dragstart'); // Card A
        const doing = w.get('[data-kanban-column="doing"]');
        await doing.trigger('dragenter');
        await doing.trigger('dragover', { clientY: 90 }); // between X and Y

        // The ghost previews the slot: after X, before Y.
        const slots = doing
            .findAll('article, .kx-drop-ghost')
            .map((el) =>
                el.classes().includes('kx-drop-ghost')
                    ? 'ghost'
                    : el.find('p').text(),
            );
        expect(slots).toEqual(['Card X', 'ghost', 'Card Y']);

        await doing.trigger('drop');
        await Promise.resolve();

        expect(lastMove()![1].body).toMatchObject({
            recordId: 1,
            status: 'doing',
            order: [3, 1, 4],
        });
        expect(titles(w, 'doing')).toEqual(['Card X', 'Card A', 'Card Y']);
        w.unmount();
    });

    it('reorders a card within its own column', async () => {
        fetchMock.mockClear();
        const w = mountBoard();
        layOut(w);

        await w.findAll('article')[0].trigger('dragstart'); // Card A
        const todo = w.get('[data-kanban-column="todo"]');
        await todo.trigger('dragenter');
        await todo.trigger('dragover', { clientY: 170 }); // below Card C

        await todo.trigger('drop');
        await Promise.resolve();

        expect(lastMove()![1].body).toMatchObject({
            recordId: 1,
            status: 'todo',
            order: [2, 1],
        });
        expect(titles(w, 'todo')).toEqual(['Card C', 'Card A']);
        w.unmount();
    });

    it('a drop that leaves the card where it is sends nothing and shows no ghost', async () => {
        fetchMock.mockClear();
        const w = mountBoard();
        layOut(w);

        await w.findAll('article')[0].trigger('dragstart'); // Card A
        const todo = w.get('[data-kanban-column="todo"]');
        await todo.trigger('dragenter');
        await todo.trigger('dragover', { clientY: 10 }); // its own slot

        expect(todo.find('.kx-drop-ghost').exists()).toBe(false);

        await todo.trigger('drop');
        await Promise.resolve();

        expect(lastMove()).toBeUndefined();
        w.unmount();
    });

    it('moves a card with the up and down arrow keys', async () => {
        fetchMock.mockClear();
        const w = mountBoard();

        await w.findAll('article')[0].trigger('keydown', { key: 'ArrowDown' });
        await Promise.resolve();

        expect(lastMove()![1].body).toMatchObject({
            recordId: 1,
            status: 'todo',
            order: [2, 1],
        });
        expect(titles(w, 'todo')).toEqual(['Card C', 'Card A']);

        // Already last: a no-op.
        fetchMock.mockClear();
        await w
            .get('[data-kanban-card="1"]')
            .trigger('keydown', { key: 'ArrowDown' });
        await Promise.resolve();
        expect(lastMove()).toBeUndefined();
        w.unmount();
    });

    it('puts both columns back exactly when the move is refused', async () => {
        fetchMock.mockClear();
        fetchMock.mockRejectedValueOnce(new Error('nope'));
        const w = mountBoard();
        layOut(w);

        await w.findAll('article')[0].trigger('dragstart'); // Card A
        const doing = w.get('[data-kanban-column="doing"]');
        await doing.trigger('dragenter');
        await doing.trigger('dragover', { clientY: 10 }); // above X
        await doing.trigger('drop');
        await Promise.resolve();
        await Promise.resolve();

        expect(titles(w, 'todo')).toEqual(['Card A', 'Card C']);
        expect(titles(w, 'doing')).toEqual(['Card X', 'Card Y']);
        w.unmount();
    });

    it('a plain board appends, sends no order and ignores the up and down keys', async () => {
        fetchMock.mockClear();
        const w = mountBoard({ ...board(), reorderable: false });
        layOut(w);

        await w.findAll('article')[0].trigger('dragstart'); // Card A
        const doing = w.get('[data-kanban-column="doing"]');
        await doing.trigger('dragenter');
        await doing.trigger('dragover', { clientY: 10 });
        await doing.trigger('drop');
        await Promise.resolve();

        expect(lastMove()![1].body.order).toBeUndefined();
        expect(titles(w, 'doing')).toEqual(['Card X', 'Card Y', 'Card A']);

        fetchMock.mockClear();
        await w
            .get('[data-kanban-card="2"]')
            .trigger('keydown', { key: 'ArrowUp' });
        await Promise.resolve();
        expect(lastMove()).toBeUndefined();
        w.unmount();
    });
});
