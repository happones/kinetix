<script setup lang="ts">
import {
    AlertTriangle,
    ArrowLeft,
    BarChart3,
    FlaskConical,
    Plus,
    Rocket,
    Settings2,
    X,
} from '@lucide/vue';
import {
    computed,
    onBeforeUnmount,
    onMounted,
    provide,
    ref,
    shallowRef,
    watch,
} from 'vue';
import { useI18n } from 'vue-i18n';
import { toast } from 'vue-sonner';
import type { KinetixFlowEngine } from '@/composables/useKinetixFlowEngine';
import { loadKinetixFlowEngine } from '@/composables/useKinetixFlowEngine';
import { useKinetixFlows } from '@/composables/useKinetixFlows';
import {
    buttonVariants,
    inputClass,
} from '@/composables/useKinetixShadcnVariants';
import type {
    KinetixFlowDetail,
    KinetixFlowGraph,
    KinetixFlowStats,
} from '@/types/kinetix';
import { FLOW_EDITOR } from './Flows/flowEditorContext';
import {
    FLOW_NODES,
    flowId as newId,
    flowKind,
    flowVariables,
} from './Flows/flowNodes';
import KinetixFlowInspector from './Flows/KinetixFlowInspector.vue';
import KinetixFlowNode from './Flows/KinetixFlowNode.vue';
import KinetixFlowSettings from './Flows/KinetixFlowSettings.vue';
import KinetixFlowSimulator from './Flows/KinetixFlowSimulator.vue';
import KinetixButton from './KinetixButton.vue';
import KinetixBadge from './primitives/KinetixBadge.vue';

/**
 * The visual bot editor: drag steps onto the canvas, wire their outputs,
 * set each one up, choose what starts the flow and on which lines. Changes
 * save to the draft as you go; Publish makes them live (running
 * conversations finish on their version). Test runs the draft in a chat
 * that sends nothing; Stats shows how many conversations reached each step.
 *
 * Needs Vue Flow registered by the app (`registerKinetixFlowEditor()`).
 * Give it a height — it fills its container.
 */
const props = withDefaults(
    defineProps<{
        flowId: number | string;
        /** Show a back button (emits `back`). */
        backable?: boolean;
    }>(),
    { backable: false },
);

const emit = defineEmits<{
    (e: 'back'): void;
    (e: 'published', flow: KinetixFlowDetail): void;
}>();

const { t } = useI18n();
const api = useKinetixFlows();

interface CanvasNode {
    id: string;
    type: string;
    position: { x: number; y: number };
    data: Record<string, unknown>;
    deletable?: boolean;
}

interface CanvasEdge {
    id: string;
    source: string;
    target: string;
    sourceHandle?: string | null;
}

const engine = shallowRef<KinetixFlowEngine | null>(null);
const engineChecked = ref(false);
const flow = ref<KinetixFlowDetail | null>(null);
const nodes = ref<CanvasNode[]>([]);
const edges = ref<CanvasEdge[]>([]);
const name = ref('');
const trigger = ref<KinetixFlowDetail['trigger']>({ type: 'keyword' });
const channelIds = ref<(number | string)[] | null>(null);
const priority = ref(0);

const selectedId = ref<string | null>(null);
const panel = ref<'auto' | 'settings' | 'test'>('auto');
const panelOpen = ref(false);
const showStats = ref(false);
const statsVersion = ref<number | string | null>(null);
const saving = ref<'saved' | 'saving' | 'dirty' | 'error'>('saved');
const publishing = ref(false);
const showProblems = ref(false);
const addOpen = ref(false);

const stats = ref<KinetixFlowStats['nodes'] | null>(null);
const activeNode = ref<string | null>(null);
const path = ref<string[]>([]);

let instance: {
    screenToFlowCoordinate?: (p: { x: number; y: number }) => {
        x: number;
        y: number;
    };
    fitView?: (options?: Record<string, unknown>) => void;
} | null = null;
let saveTimer: ReturnType<typeof setTimeout> | null = null;
let hydrating = true;
/** What was last saved: selecting or measuring nodes isn't a change. */
let savedSnapshot = '';
let fitted = false;

const problemMap = computed<Record<string, string>>(() =>
    Object.fromEntries(
        (flow.value?.problems ?? [])
            .filter((p) => p.node)
            .map((p) => [p.node as string, p.message]),
    ),
);

provide('kinetixFlowEngine', engine);
provide(FLOW_EDITOR, {
    problems: problemMap,
    stats,
    activeNode,
    path,
});

const selected = computed(
    () => nodes.value.find((node) => node.id === selectedId.value) ?? null,
);
const variables = computed(() => flowVariables(nodes.value));
const palette = computed(() =>
    Object.entries(FLOW_NODES).filter(([, kind]) => kind.addable),
);
const nodeTypes = computed(() => [
    ...new Set([
        ...Object.keys(FLOW_NODES),
        ...(flow.value?.nodeTypes ?? []),
        ...nodes.value.map((node) => node.type),
    ]),
]);
const problemCount = computed(() => flow.value?.problems.length ?? 0);

/** What the side panel shows: the test chat, the selected step, or the flow's settings. */
const panelView = computed<'test' | 'inspector' | 'settings'>(() => {
    if (panel.value === 'test') {
        return 'test';
    }

    if (
        panel.value === 'settings' ||
        !selected.value ||
        selected.value.type === 'start'
    ) {
        return 'settings';
    }

    return 'inspector';
});

function snapshot(): string {
    return JSON.stringify([
        graph(),
        name.value,
        trigger.value,
        channelIds.value,
        priority.value,
    ]);
}

function graph(): KinetixFlowGraph {
    return {
        nodes: nodes.value.map((node) => ({
            id: node.id,
            type: node.type,
            position: {
                x: Math.round(node.position.x),
                y: Math.round(node.position.y),
            },
            data: node.data,
        })),
        edges: edges.value.map((edge) => ({
            id: edge.id,
            source: edge.source,
            sourceHandle: edge.sourceHandle || 'next',
            target: edge.target,
        })),
    };
}

function hydrate(detail: KinetixFlowDetail): void {
    hydrating = true;
    flow.value = detail;
    name.value = detail.name;
    trigger.value = { ...detail.trigger };
    channelIds.value = detail.channelIds;
    priority.value = detail.priority;
    nodes.value = detail.graph.nodes.map((node) => ({
        ...node,
        deletable: node.type !== 'start',
    }));
    edges.value = detail.graph.edges.map((edge) => ({ ...edge }));
    savedSnapshot = snapshot();
    queueMicrotask(() => {
        hydrating = false;
    });
}

onMounted(async () => {
    const [loadedEngine, detail] = await Promise.all([
        loadKinetixFlowEngine(),
        api.show(props.flowId),
    ]);

    engine.value = loadedEngine;
    engineChecked.value = true;

    if (detail) {
        hydrate(detail);
    }
});

onBeforeUnmount(() => {
    if (saveTimer) {
        clearTimeout(saveTimer);
        void persist();
    }
});

// ── Saving ───────────────────────────────────────────────────────────────

async function persist(): Promise<boolean> {
    if (!flow.value) {
        return false;
    }

    saveTimer = null;
    saving.value = 'saving';
    const sending = snapshot();

    try {
        const detail = await api.save(flow.value.id, {
            name: name.value.trim() || flow.value.name,
            graph: graph(),
            trigger: trigger.value,
            channel_ids: channelIds.value,
            priority: priority.value,
        });

        if (detail) {
            // Keep what's on screen; take the server's verdict (problems, flags).
            flow.value = {
                ...detail,
                graph: graph(),
            };
        }

        savedSnapshot = sending;
        saving.value = snapshot() === savedSnapshot ? 'saved' : 'dirty';

        return true;
    } catch (error) {
        saving.value = 'error';
        toast.error(error instanceof Error ? error.message : String(error));

        return false;
    }
}

function scheduleSave(): void {
    if (hydrating) {
        return;
    }

    if (snapshot() === savedSnapshot) {
        if (saving.value === 'dirty') {
            saving.value = 'saved';
        }

        return;
    }

    saving.value = 'dirty';

    if (saveTimer) {
        clearTimeout(saveTimer);
    }

    saveTimer = setTimeout(() => void persist(), 800);
}

watch([nodes, edges, name, trigger, channelIds, priority], scheduleSave, {
    deep: true,
});

async function publish(): Promise<void> {
    if (!flow.value) {
        return;
    }

    publishing.value = true;

    try {
        if (saving.value !== 'saved' && !(await persist())) {
            return;
        }

        const detail = await api.publish(flow.value.id);

        if (detail) {
            flow.value = { ...detail, graph: graph() };
            toast.success(
                t('kinetix.flow_published', { version: detail.version ?? 1 }),
            );
            emit('published', detail);
        }
    } catch (error) {
        const problems = (error as { problems?: unknown }).problems;
        toast.error(error instanceof Error ? error.message : String(error));

        if (problems) {
            showProblems.value = true;
        }
    } finally {
        publishing.value = false;
    }
}

// ── Canvas ───────────────────────────────────────────────────────────────

let measured = false;

function onPaneReady(flowInstance: typeof instance): void {
    instance = flowInstance;
    fit();
}

function onNodesInitialized(): void {
    measured = true;
    fit();
}

/** Frame the whole flow once the canvas is ready and its nodes are measured (either may come first). */
function fit(): void {
    if (fitted || !instance || !measured) {
        return;
    }

    fitted = true;
    requestAnimationFrame(() =>
        instance?.fitView?.({ padding: 0.15, maxZoom: 1 }),
    );
}

function onConnect(connection: {
    source: string;
    target: string;
    sourceHandle?: string | null;
}): void {
    if (connection.source === connection.target) {
        return;
    }

    const handle = connection.sourceHandle || 'next';

    // One wire per output: a new one replaces the old.
    edges.value = [
        ...edges.value.filter(
            (edge) =>
                !(
                    edge.source === connection.source &&
                    (edge.sourceHandle || 'next') === handle
                ),
        ),
        {
            id: newId('edge'),
            source: connection.source,
            sourceHandle: handle,
            target: connection.target,
        },
    ];
}

function onNodeClick(event: { node: { id: string } }): void {
    select(event.node.id);
}

/** A deleted node (the Delete key) can't stay selected. */
function onNodesChange(changes: { type: string; id: string }[]): void {
    if (changes.some((c) => c.type === 'remove' && c.id === selectedId.value)) {
        select(null);
    }
}

function select(id: string | null): void {
    selectedId.value = id;
    panel.value = 'auto';
    panelOpen.value = id !== null;
}

function centerPosition(): { x: number; y: number } {
    const last = nodes.value[nodes.value.length - 1];

    return last
        ? { x: last.position.x + 320, y: last.position.y }
        : { x: 120, y: 120 };
}

function addNode(type: string, position?: { x: number; y: number }): void {
    const node: CanvasNode = {
        id: newId(type),
        type,
        position: position ?? centerPosition(),
        data: flowKind(type).defaults(),
        deletable: true,
    };

    // Wire it after the selected step's free output, to keep building in one click.
    const from = selected.value;

    if (from && !position) {
        const free = flowKind(from.type)
            .outputs(from.data, t)
            .find(
                (output) =>
                    !edges.value.some(
                        (e) =>
                            e.source === from.id &&
                            (e.sourceHandle || 'next') === output.id,
                    ),
            );

        if (free) {
            node.position = { x: from.position.x + 320, y: from.position.y };
            edges.value = [
                ...edges.value,
                {
                    id: newId('edge'),
                    source: from.id,
                    sourceHandle: free.id,
                    target: node.id,
                },
            ];
        }
    }

    nodes.value = [...nodes.value, node];
    addOpen.value = false;
    select(node.id);
}

function onDragStart(event: DragEvent, type: string): void {
    event.dataTransfer?.setData('application/kinetix-flow-node', type);

    if (event.dataTransfer) {
        event.dataTransfer.effectAllowed = 'move';
    }
}

function onDrop(event: DragEvent): void {
    const type = event.dataTransfer?.getData('application/kinetix-flow-node');

    if (!type) {
        return;
    }

    event.preventDefault();
    const position = instance?.screenToFlowCoordinate?.({
        x: event.clientX - 128,
        y: event.clientY - 30,
    });
    addNode(type, position);
}

function updateSelected(data: Record<string, unknown>): void {
    nodes.value = nodes.value.map((node) =>
        node.id === selectedId.value ? { ...node, data } : node,
    );

    // Outputs that no longer exist (a removed option) lose their wires.
    const node = nodes.value.find((n) => n.id === selectedId.value);

    if (node) {
        const outputs = new Set(
            flowKind(node.type)
                .outputs(data, t)
                .map((o) => o.id),
        );
        edges.value = edges.value.filter(
            (edge) =>
                edge.source !== node.id ||
                outputs.has(edge.sourceHandle || 'next'),
        );
    }
}

function deleteSelected(): void {
    const id = selectedId.value;

    if (!id || selected.value?.type === 'start') {
        return;
    }

    nodes.value = nodes.value.filter((node) => node.id !== id);
    edges.value = edges.value.filter(
        (edge) => edge.source !== id && edge.target !== id,
    );
    select(null);
}

function focusProblem(node: string | null): void {
    showProblems.value = false;

    if (node) {
        select(node);
    } else {
        panel.value = 'settings';
        panelOpen.value = true;
    }
}

// ── Test & stats ─────────────────────────────────────────────────────────

function openTest(): void {
    panel.value = panel.value === 'test' ? 'auto' : 'test';
    panelOpen.value = panel.value === 'test' || selectedId.value !== null;

    if (panel.value !== 'test') {
        activeNode.value = null;
        path.value = [];
    }
}

function simulate(state: Record<string, unknown> | null, input: string | null) {
    return api.simulate(props.flowId, graph(), state, input);
}

function onPosition(node: string | null, visited: string[]): void {
    activeNode.value = node;
    path.value = visited;
}

async function loadStats(): Promise<void> {
    const data = await api.stats(props.flowId, statsVersion.value);
    stats.value = data?.nodes ?? {};
}

watch(showStats, (on) => {
    if (on) {
        void loadStats();
    } else {
        stats.value = null;
    }
});

watch(statsVersion, () => {
    if (showStats.value) {
        void loadStats();
    }
});

const statusColor = computed(
    () =>
        ({ active: 'success', draft: 'gray', inactive: 'warning' })[
            flow.value?.status ?? 'draft'
        ],
);
</script>

<template>
    <div
        class="rounded-xl relative flex h-full min-h-[36rem] flex-col overflow-hidden border border-border bg-card"
        data-test="flow-editor"
    >
        <!-- Toolbar -->
        <header
            class="gap-2 px-3 py-2 flex flex-wrap items-center border-b border-border"
        >
            <button
                v-if="backable"
                type="button"
                :class="buttonVariants({ variant: 'ghost', size: 'icon-sm' })"
                :aria-label="t('kinetix.flow_back')"
                @click="emit('back')"
            >
                <ArrowLeft class="size-4" />
            </button>

            <input
                v-model="name"
                :aria-label="t('kinetix.flow_name')"
                class="min-w-0 h-8 px-2 text-sm font-semibold max-w-64 flex-1 rounded-md border border-transparent bg-transparent outline-none hover:border-border focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                data-test="flow-name"
            />

            <KinetixBadge v-if="flow" :color="statusColor" class="gap-1.5">
                {{ t(`kinetix.flow_status_${flow.status}`) }}
                <template v-if="flow.version">· v{{ flow.version }}</template>
            </KinetixBadge>

            <span
                class="text-xs sm:inline hidden text-muted-foreground"
                aria-live="polite"
                data-test="flow-save-state"
            >
                {{ t(`kinetix.flow_save_${saving}`) }}
            </span>

            <div class="gap-1.5 ml-auto flex items-center">
                <div v-if="problemCount" class="relative">
                    <button
                        type="button"
                        :class="
                            buttonVariants({ variant: 'ghost', size: 'sm' })
                        "
                        class="text-destructive"
                        :aria-expanded="showProblems"
                        data-test="flow-problems"
                        @click="showProblems = !showProblems"
                    >
                        <AlertTriangle class="size-4" />
                        {{ problemCount }}
                    </button>
                    <div
                        v-if="showProblems"
                        class="right-0 top-10 w-72 p-2 rounded-lg shadow-lg absolute z-[var(--kinetix-z-popover,120)] border border-border bg-popover text-popover-foreground"
                    >
                        <p
                            class="px-2 pb-1 text-xs font-medium text-muted-foreground"
                        >
                            {{ t('kinetix.flow_problems') }}
                        </p>
                        <button
                            v-for="(problem, index) in flow?.problems ?? []"
                            :key="index"
                            type="button"
                            class="px-2 py-1.5 text-sm w-full rounded-md text-left hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                            @click="focusProblem(problem.node)"
                        >
                            {{ problem.message }}
                        </button>
                    </div>
                </div>

                <button
                    type="button"
                    :class="
                        buttonVariants({
                            variant: showStats ? 'secondary' : 'ghost',
                            size: 'sm',
                        })
                    "
                    :aria-pressed="showStats"
                    :disabled="!flow?.version"
                    :title="
                        flow?.version
                            ? undefined
                            : t('kinetix.flow_stats_unpublished')
                    "
                    data-test="flow-stats-toggle"
                    @click="showStats = !showStats"
                >
                    <BarChart3 class="size-4" />
                    <span class="md:inline hidden">{{
                        t('kinetix.flow_stats')
                    }}</span>
                </button>
                <select
                    v-if="showStats && (flow?.versions.length ?? 0) > 1"
                    v-model="statsVersion"
                    :class="[inputClass, 'h-8 w-24 text-xs md:text-xs']"
                    :aria-label="t('kinetix.flow_version')"
                >
                    <option :value="null">
                        {{ t('kinetix.flow_version_live') }}
                    </option>
                    <option
                        v-for="version in flow?.versions ?? []"
                        :key="version.id"
                        :value="version.id"
                    >
                        v{{ version.number }}
                    </option>
                </select>

                <button
                    type="button"
                    :class="
                        buttonVariants({
                            variant: panel === 'test' ? 'secondary' : 'ghost',
                            size: 'sm',
                        })
                    "
                    :aria-pressed="panel === 'test'"
                    data-test="flow-test-toggle"
                    @click="openTest"
                >
                    <FlaskConical class="size-4" />
                    <span class="md:inline hidden">{{
                        t('kinetix.flow_test')
                    }}</span>
                </button>

                <button
                    type="button"
                    :class="
                        buttonVariants({ variant: 'ghost', size: 'icon-sm' })
                    "
                    :aria-label="t('kinetix.flow_settings')"
                    data-test="flow-settings-toggle"
                    @click="
                        panel = 'settings';
                        panelOpen = true;
                    "
                >
                    <Settings2 class="size-4" />
                </button>

                <KinetixButton
                    size="sm"
                    :loading="publishing"
                    :disabled="problemCount > 0 || !flow"
                    data-test="flow-publish"
                    @click="publish"
                >
                    <Rocket class="size-4" />
                    {{ t('kinetix.flow_publish') }}
                </KinetixButton>
            </div>
        </header>

        <div class="min-h-0 relative flex flex-1">
            <!-- Palette -->
            <aside
                class="w-52 p-3 space-y-1 md:block hidden shrink-0 overflow-y-auto border-r border-border"
                :aria-label="t('kinetix.flow_palette')"
            >
                <p class="px-1 pb-1 text-xs font-medium text-muted-foreground">
                    {{ t('kinetix.flow_palette') }}
                </p>
                <button
                    v-for="[type, kind] in palette"
                    :key="type"
                    type="button"
                    draggable="true"
                    class="gap-2 px-2 py-1.5 text-sm flex w-full cursor-grab items-center rounded-md text-left transition-colors hover:bg-accent focus-visible:bg-accent focus-visible:outline-none active:cursor-grabbing"
                    :data-test="`flow-add-${type}`"
                    @dragstart="onDragStart($event, type)"
                    @click="addNode(type)"
                >
                    <span
                        class="size-6 inline-flex shrink-0 items-center justify-center rounded-md"
                        :class="kind.tone"
                    >
                        <component :is="kind.icon" class="size-3.5" />
                    </span>
                    {{ t(`kinetix.flow_node_${type}`) }}
                </button>
                <p class="px-1 pt-3 text-[11px] text-muted-foreground">
                    {{ t('kinetix.flow_palette_hint') }}
                </p>
            </aside>

            <!-- Canvas -->
            <div
                class="min-w-0 relative flex-1 bg-muted/20"
                @dragover.prevent
                @drop="onDrop"
            >
                <!-- Adding a step on small screens (no palette there). -->
                <div class="left-3 top-3 md:hidden absolute z-10">
                    <button
                        type="button"
                        :class="buttonVariants({ size: 'sm' })"
                        :aria-expanded="addOpen"
                        data-test="flow-add-menu"
                        @click="addOpen = !addOpen"
                    >
                        <Plus class="size-4" />
                        {{ t('kinetix.flow_add_step') }}
                    </button>
                    <div
                        v-if="addOpen"
                        class="mt-1 w-56 p-1 rounded-lg shadow-lg border border-border bg-popover"
                    >
                        <button
                            v-for="[type, kind] in palette"
                            :key="type"
                            type="button"
                            class="gap-2 px-2 py-1.5 text-sm flex w-full items-center rounded-md text-left hover:bg-accent focus-visible:bg-accent focus-visible:outline-none"
                            @click="addNode(type)"
                        >
                            <component :is="kind.icon" class="size-4" />
                            {{ t(`kinetix.flow_node_${type}`) }}
                        </button>
                    </div>
                </div>

                <div
                    v-if="engineChecked && !engine"
                    class="p-6 flex h-full items-center justify-center"
                    data-test="flow-engine-missing"
                >
                    <div class="max-w-md space-y-3 text-center">
                        <p class="text-sm font-semibold text-foreground">
                            {{ t('kinetix.flow_engine_missing') }}
                        </p>
                        <p class="text-sm text-muted-foreground">
                            {{ t('kinetix.flow_engine_missing_help') }}
                        </p>
                        <pre
                            class="p-3 font-mono overflow-x-auto rounded-md bg-muted text-left text-[11px] text-foreground"
                        >
npm install @vue-flow/core @vue-flow/background @vue-flow/controls @vue-flow/minimap</pre
                        >
                    </div>
                </div>

                <component
                    :is="engine.VueFlow"
                    v-else-if="engine && flow"
                    v-model:nodes="nodes"
                    v-model:edges="edges"
                    class="kinetix-flow-canvas"
                    :default-edge-options="{
                        type: 'smoothstep',
                        markerEnd: 'arrowclosed',
                    }"
                    :delete-key-code="['Delete']"
                    :min-zoom="0.2"
                    :max-zoom="1.5"
                    :snap-to-grid="true"
                    :snap-grid="[20, 20]"
                    @pane-ready="onPaneReady"
                    @nodes-initialized="onNodesInitialized"
                    @connect="onConnect"
                    @node-click="onNodeClick"
                    @pane-click="select(null)"
                    @nodes-change="onNodesChange"
                >
                    <template
                        v-for="type in nodeTypes"
                        :key="type"
                        #[`node-${type}`]="nodeProps"
                    >
                        <KinetixFlowNode
                            :id="nodeProps.id"
                            :type="nodeProps.type"
                            :data="nodeProps.data"
                            :selected="nodeProps.selected"
                        />
                    </template>
                    <component
                        :is="engine.Background"
                        v-if="engine.Background"
                        :gap="20"
                        pattern-color="var(--color-border)"
                    />
                    <component
                        :is="engine.Controls"
                        v-if="engine.Controls"
                        position="bottom-left"
                    />
                    <component
                        :is="engine.MiniMap"
                        v-if="engine.MiniMap"
                        position="bottom-right"
                        pannable
                        zoomable
                        :width="160"
                        :height="110"
                        mask-color="color-mix(in oklab, var(--color-foreground) 10%, transparent)"
                        node-color="var(--color-muted-foreground)"
                        class="lg:block hidden"
                    />
                </component>

                <div
                    v-else
                    class="inset-0 absolute flex items-center justify-center"
                    aria-hidden="true"
                >
                    <div
                        class="size-10 motion-safe:animate-spin rounded-full border-2 border-muted border-t-primary"
                    />
                </div>
            </div>

            <!-- Side panel: the selected step, the flow's settings, or the test chat -->
            <aside
                class="inset-y-0 right-0 p-4 shadow-xl sm:w-96 lg:static lg:z-auto lg:block lg:w-96 lg:shrink-0 lg:shadow-none absolute z-20 w-full overflow-y-auto border-l border-border bg-card"
                :class="panelOpen || panel === 'test' ? 'block' : 'hidden'"
                data-test="flow-panel"
            >
                <button
                    type="button"
                    :class="
                        buttonVariants({ variant: 'ghost', size: 'icon-sm' })
                    "
                    class="right-2 top-2 lg:hidden absolute"
                    :aria-label="t('kinetix.close')"
                    @click="
                        panelOpen = false;
                        panel = 'auto';
                    "
                >
                    <X class="size-4" />
                </button>

                <KinetixFlowSimulator
                    v-if="panelView === 'test'"
                    :run="simulate"
                    @position="onPosition"
                />
                <KinetixFlowInspector
                    v-else-if="panelView === 'inspector' && selected"
                    :key="selected.id"
                    :node="selected"
                    :variables="variables"
                    :members="flow?.members ?? []"
                    :problem="problemMap[selected.id] ?? null"
                    @update="updateSelected"
                    @delete="deleteSelected"
                />
                <KinetixFlowSettings
                    v-else-if="flow"
                    v-model:trigger="trigger"
                    v-model:channel-ids="channelIds"
                    v-model:priority="priority"
                    :lines="flow.lines"
                />
            </aside>
        </div>
    </div>
</template>

<style>
/* Vue Flow on Kinetix's tokens: wires, selection and controls follow the theme. */
.kinetix-flow-canvas .vue-flow__edge-path {
    stroke: var(--color-muted-foreground);
    stroke-width: 1.5;
}

.kinetix-flow-canvas .vue-flow__edge.selected .vue-flow__edge-path {
    stroke: var(--color-primary);
}

.kinetix-flow-canvas .vue-flow__node {
    padding: 0;
    border: 0;
    background: transparent;
    box-shadow: none;
}

.kinetix-flow-canvas .vue-flow__controls-button {
    background: var(--color-card);
    border-color: var(--color-border);
    color: var(--color-foreground);
    fill: currentColor;
}

.kinetix-flow-canvas .vue-flow__minimap {
    background: var(--color-card);
}
</style>
