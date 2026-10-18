import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import { flowKind } from '@/components/Flows/flowNodes';
import KinetixFlowEditor from '@/components/KinetixFlowEditor.vue';
import KinetixFlowList from '@/components/KinetixFlowList.vue';
import { registerKinetixFlowEditor } from '@/composables/useKinetixFlowEngine';
import type { KinetixFlowDetail } from '@/types/kinetix';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: {} } },
});

/**
 * A stand-in for Vue Flow: renders each node through its `node-<type>` slot
 * and turns a click on it into `node-click`.
 */
const FakeVueFlow = defineComponent({
    props: { nodes: { type: Array, default: () => [] } },
    emits: [
        'node-click',
        'pane-ready',
        'nodes-initialized',
        'update:nodes',
        'update:edges',
    ],
    setup(props, { slots, emit }) {
        return () =>
            h(
                'div',
                { 'data-test': 'fake-canvas' },
                (
                    props.nodes as {
                        id: string;
                        type: string;
                        data: Record<string, unknown>;
                    }[]
                ).map((node) =>
                    h(
                        'div',
                        { onClick: () => emit('node-click', { node }) },
                        slots[`node-${node.type}`]?.({
                            id: node.id,
                            type: node.type,
                            data: node.data,
                            selected: false,
                        }),
                    ),
                ),
            );
    },
});

const fakeEngine = {
    core: {
        VueFlow: FakeVueFlow,
        Handle: defineComponent({ render: () => null }),
        Position: { Left: 'left', Right: 'right' },
    },
};

const detail = (
    overrides: Partial<KinetixFlowDetail> = {},
): KinetixFlowDetail => ({
    id: 1,
    name: 'Welcome',
    status: 'draft',
    trigger: { type: 'keyword', keywords: ['hola'], match: 'contains' },
    channelIds: null,
    priority: 0,
    version: null,
    unpublished: true,
    runs: 0,
    activeRuns: 0,
    updatedAt: null,
    graph: {
        nodes: [
            { id: 'start', type: 'start', position: { x: 0, y: 0 }, data: {} },
            {
                id: 'hi',
                type: 'message',
                position: { x: 300, y: 0 },
                data: { text: 'Hola' },
            },
        ],
        edges: [
            { id: 'e1', source: 'start', sourceHandle: 'next', target: 'hi' },
        ],
    },
    problems: [],
    versions: [],
    nodeTypes: ['start', 'message'],
    lines: [],
    members: [],
    ...overrides,
});

function serve(routes: Record<string, (body?: unknown) => unknown>): void {
    fetchMock.mockImplementation(
        async (
            url: string,
            options: { method?: string; body?: unknown } = {},
        ) => {
            const key = `${options.method ?? 'GET'} ${url}`;
            const match = Object.keys(routes).find((route) =>
                key.startsWith(route),
            );

            if (!match) {
                throw new Error(`Unexpected ${key}`);
            }

            return routes[match](options.body);
        },
    );
}

beforeEach(() => {
    fetchMock.mockReset();
});

afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = '';
});

describe('KinetixFlowEditor', () => {
    it('explains how to install the canvas when the app did not register it', async () => {
        serve({ 'GET /_kinetix/flows/1': () => ({ flow: detail() }) });
        registerKinetixFlowEditor(async () => {
            throw new Error('not installed');
        });

        const w = mount(KinetixFlowEditor, {
            props: { flowId: 1 },
            global: { plugins: [i18n] },
        });
        await flushPromises();

        expect(w.find('[data-test="flow-engine-missing"]').exists()).toBe(true);
        w.unmount();
    });

    it('edits a step and saves the draft by itself', async () => {
        vi.useFakeTimers();
        let saved: Record<string, unknown> | null = null;
        serve({
            'GET /_kinetix/flows/1': () => ({ flow: detail() }),
            'PUT /_kinetix/flows/1': (body) => {
                saved = body as Record<string, unknown>;

                return { flow: detail() };
            },
        });
        registerKinetixFlowEditor(async () => fakeEngine);

        const w = mount(KinetixFlowEditor, {
            props: { flowId: 1 },
            global: { plugins: [i18n] },
        });
        await flushPromises();

        await w.get('[data-test="flow-node-hi"]').trigger('click');
        await w
            .get('[data-test="flow-field-text"]')
            .setValue('¡Hola {{contact.name}}!');
        await vi.advanceTimersByTimeAsync(900);
        await flushPromises();

        const graph = (
            saved as {
                graph?: {
                    nodes: { id: string; data: Record<string, unknown> }[];
                };
            } | null
        )?.graph;
        expect(graph?.nodes.find((node) => node.id === 'hi')?.data.text).toBe(
            '¡Hola {{contact.name}}!',
        );
        expect(w.get('[data-test="flow-save-state"]').text()).toBe(
            'kinetix.flow_save_saved',
        );
        w.unmount();
    });

    it('adds a step wired after the selected one', async () => {
        serve({ 'GET /_kinetix/flows/1': () => ({ flow: detail() }) });
        registerKinetixFlowEditor(async () => fakeEngine);

        const w = mount(KinetixFlowEditor, {
            props: { flowId: 1 },
            global: { plugins: [i18n] },
        });
        await flushPromises();

        await w.get('[data-test="flow-node-hi"]').trigger('click');
        await w.get('[data-test="flow-add-question"]').trigger('click');
        await flushPromises();

        const nodes = w.findAll('[data-test^="flow-node-question-"]');
        expect(nodes).toHaveLength(1);
        expect(w.find('[data-test="flow-inspector"]').text()).toContain(
            'kinetix.flow_node_question',
        );
        w.unmount();
    });

    it('blocks publishing while the draft has problems, and publishes once fixed', async () => {
        serve({
            'GET /_kinetix/flows/1': () => ({
                flow: detail({
                    problems: [{ node: 'hi', message: 'Message is required.' }],
                }),
            }),
        });
        registerKinetixFlowEditor(async () => fakeEngine);

        const blocked = mount(KinetixFlowEditor, {
            props: { flowId: 1 },
            global: { plugins: [i18n] },
        });
        await flushPromises();
        expect(
            blocked.get('[data-test="flow-publish"]').attributes('disabled'),
        ).toBeDefined();
        expect(blocked.get('[data-test="flow-node-hi"]').text()).toContain(
            'Message is required.',
        );
        blocked.unmount();

        serve({
            'GET /_kinetix/flows/1': () => ({ flow: detail() }),
            'POST /_kinetix/flows/1/publish': () => ({
                flow: detail({
                    status: 'active',
                    version: 1,
                    unpublished: false,
                }),
            }),
        });
        const ready = mount(KinetixFlowEditor, {
            props: { flowId: 1 },
            global: { plugins: [i18n] },
        });
        await flushPromises();
        await ready.get('[data-test="flow-publish"]').trigger('click');
        await flushPromises();

        expect(ready.emitted('published')?.[0]?.[0]).toMatchObject({
            version: 1,
        });
        ready.unmount();
    });

    it('tests the draft in a chat that answers by tapping an option', async () => {
        const turns: { input: unknown; state: unknown }[] = [];
        serve({
            'GET /_kinetix/flows/1': () => ({ flow: detail() }),
            'POST /_kinetix/flows/1/simulate': (body) => {
                const { input, state } = body as {
                    input: unknown;
                    state: unknown;
                };
                turns.push({ input, state });

                return input === null
                    ? {
                          entries: [
                              {
                                  kind: 'bot',
                                  text: '¿Qué necesitas?',
                                  options: ['Precios', 'Soporte'],
                              },
                          ],
                          state: { node: 'menu' },
                          node: 'menu',
                          status: 'waiting_reply',
                          path: ['start', 'menu'],
                      }
                    : {
                          entries: [
                              {
                                  kind: 'bot',
                                  text: 'Te paso con soporte',
                                  options: [],
                              },
                          ],
                          state: { node: 'end' },
                          node: null,
                          status: 'completed',
                          path: ['end'],
                      };
            },
        });
        registerKinetixFlowEditor(async () => fakeEngine);

        const w = mount(KinetixFlowEditor, {
            props: { flowId: 1 },
            global: { plugins: [i18n] },
        });
        await flushPromises();
        await w.get('[data-test="flow-test-toggle"]').trigger('click');
        await w.get('[data-test="flow-test-restart"]').trigger('click');
        await flushPromises();

        const options = w.findAll('[data-test="flow-test-option"]');
        expect(options.map((o) => o.text())).toEqual(['Precios', 'Soporte']);

        await options[1].trigger('click');
        await flushPromises();

        expect(w.text()).toContain('Te paso con soporte');
        expect(turns[1]).toEqual({ input: 'Soporte', state: { node: 'menu' } });
        w.unmount();
    });
});

describe('flow nodes', () => {
    it('exposes one output per option plus anything else, as the PHP node does', () => {
        const outputs = flowKind('options').outputs(
            {
                options: [
                    { id: 'a', label: 'Ventas' },
                    { id: 'b', label: '' },
                ],
            },
            (key) => key,
        );

        expect(outputs.map((o) => o.id)).toEqual(['a', 'b', 'other']);
        expect(outputs[1].label).toBe('kinetix.flow_option 2');
    });
});

describe('KinetixFlowList', () => {
    it('lists the flows and opens a new one in the editor', async () => {
        serve({
            'GET /_kinetix/flows': () => ({
                flows: [
                    {
                        ...detail(),
                        status: 'active',
                        version: 2,
                        runs: 12,
                        activeRuns: 1,
                        unpublished: false,
                    },
                ],
                lines: [],
            }),
            'POST /_kinetix/flows': () => ({
                flow: detail({ id: 9, name: 'Encuesta' }),
            }),
        });

        const w = mount(KinetixFlowList, {
            attachTo: document.body,
            global: { plugins: [i18n] },
        });
        await flushPromises();
        expect(w.text()).toContain('Welcome');

        await w.get('[data-test="flow-create"]').trigger('click');
        await flushPromises();
        const input = document.querySelector(
            '[data-test="flow-create-name"]',
        ) as HTMLInputElement;
        input.value = 'Encuesta';
        input.dispatchEvent(new Event('input'));
        (
            document.querySelector('#kinetix-flow-create') as HTMLFormElement
        ).dispatchEvent(new Event('submit'));
        await flushPromises();

        expect(w.emitted('edit')?.[0]?.[0]).toMatchObject({ id: 9 });
        w.unmount();
    });
});
