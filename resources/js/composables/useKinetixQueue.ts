import { usePage } from '@inertiajs/vue3';
import { onUnmounted, ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixQueueSnapshot, KinetixSharedProps } from '@/types';

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

    let timer: ReturnType<typeof setInterval> | null = null;

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

    function start(): void {
        void load();

        if (timer === null && interval > 0) {
            timer = setInterval(() => void load(), interval);
        }
    }

    function stop(): void {
        if (timer !== null) {
            clearInterval(timer);
            timer = null;
        }
    }

    onUnmounted(stop);

    return { snapshot, loading, failed, load, start, stop };
}
