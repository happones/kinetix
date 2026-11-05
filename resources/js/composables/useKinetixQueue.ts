import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import { useKinetixPolling } from '@/composables/useKinetixPolling';
import type { KinetixQueueSnapshot, KinetixSharedProps } from '@/types/kinetix';

/**
 * Live queue-health metrics for <KinetixQueueStats>: loads a snapshot from the
 * gated endpoint and polls it on an interval (from the shared `kinetix_queue`
 * config). Horizon-aware on the server; works without it too.
 */
export function useKinetixQueue() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/queue`;
    const interval = page.props.kinetix_queue?.poll ?? 5000;

    const snapshot = ref<KinetixQueueSnapshot | null>(null);
    const loading = ref(false);
    const failed = ref(false);

    async function load(): Promise<void> {
        loading.value = true;

        try {
            snapshot.value = await kinetixFetch<KinetixQueueSnapshot>(base());
            failed.value = false;
        } catch {
            failed.value = true;
        } finally {
            loading.value = false;
        }
    }

    const polling = useKinetixPolling(load, { interval });
    const start = (): void => polling.start();
    const stop = (): void => polling.stop();

    async function retry(id: number | string): Promise<void> {
        await kinetixFetch(`${base()}/retry`, { method: 'POST', body: { id } });
        await load();
    }

    async function forget(id: number | string): Promise<void> {
        await kinetixFetch(`${base()}/failed`, {
            method: 'DELETE',
            body: { id },
        });
        await load();
    }

    return { snapshot, loading, failed, load, start, stop, retry, forget };
}
