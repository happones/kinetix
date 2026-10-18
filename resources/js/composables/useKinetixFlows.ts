import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type {
    KinetixFlow,
    KinetixFlowDetail,
    KinetixFlowGraph,
    KinetixFlowSimulation,
    KinetixFlowStats,
    KinetixSharedProps,
} from '@/types/kinetix';

/**
 * Bot flows over Kinetix's `flows` endpoints: list, create, open, save the
 * draft, publish, turn on and off, duplicate, delete, run the simulator and
 * read per-node statistics.
 */
export function useKinetixFlows() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/flows`;

    const flows = ref<KinetixFlow[]>([]);
    const lines = ref<
        { id: number | string; name: string; provider: string }[]
    >([]);
    const loading = ref(false);
    const loaded = ref(false);

    async function load(): Promise<void> {
        loading.value = true;

        try {
            const data = await kinetixFetch<{
                flows: KinetixFlow[];
                lines: typeof lines.value;
            }>(base());
            flows.value = data?.flows ?? [];
            lines.value = data?.lines ?? [];
        } finally {
            loading.value = false;
            loaded.value = true;
        }
    }

    async function create(name: string): Promise<KinetixFlowDetail | null> {
        const data = await kinetixFetch<{ flow: KinetixFlowDetail }>(base(), {
            method: 'POST',
            body: { name },
        });

        return data?.flow ?? null;
    }

    async function show(
        id: number | string,
    ): Promise<KinetixFlowDetail | null> {
        const data = await kinetixFetch<{ flow: KinetixFlowDetail }>(
            `${base()}/${id}`,
        );

        return data?.flow ?? null;
    }

    async function save(
        id: number | string,
        changes: {
            name?: string;
            graph?: KinetixFlowGraph;
            trigger?: KinetixFlow['trigger'];
            channel_ids?: (number | string)[] | null;
            priority?: number;
        },
    ): Promise<KinetixFlowDetail | null> {
        const data = await kinetixFetch<{ flow: KinetixFlowDetail }>(
            `${base()}/${id}`,
            { method: 'PUT', body: changes },
        );

        return data?.flow ?? null;
    }

    async function action(
        id: number | string,
        name: 'publish' | 'activate' | 'deactivate' | 'duplicate',
    ): Promise<KinetixFlowDetail | null> {
        const data = await kinetixFetch<{ flow: KinetixFlowDetail }>(
            `${base()}/${id}/${name}`,
            { method: 'POST' },
        );

        return data?.flow ?? null;
    }

    const publish = (id: number | string) => action(id, 'publish');
    const activate = (id: number | string) => action(id, 'activate');
    const deactivate = (id: number | string) => action(id, 'deactivate');
    const duplicate = (id: number | string) => action(id, 'duplicate');

    async function remove(id: number | string): Promise<void> {
        await kinetixFetch(`${base()}/${id}`, { method: 'DELETE' });
        flows.value = flows.value.filter((flow) => flow.id !== id);
    }

    async function simulate(
        id: number | string,
        graph: KinetixFlowGraph,
        state: Record<string, unknown> | null,
        input: string | null,
    ): Promise<KinetixFlowSimulation | null> {
        return kinetixFetch<KinetixFlowSimulation>(`${base()}/${id}/simulate`, {
            method: 'POST',
            body: { graph, state, input },
        });
    }

    async function stats(
        id: number | string,
        version?: number | string | null,
    ): Promise<KinetixFlowStats | null> {
        return kinetixFetch<KinetixFlowStats>(
            `${base()}/${id}/stats${version ? `?version=${version}` : ''}`,
        );
    }

    return {
        flows,
        lines,
        loading,
        loaded,
        load,
        create,
        show,
        save,
        publish,
        activate,
        deactivate,
        duplicate,
        remove,
        simulate,
        stats,
    };
}
