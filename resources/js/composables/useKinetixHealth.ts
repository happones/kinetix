import { usePage } from '@inertiajs/vue3';
import { onUnmounted, ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type {
    KinetixHealthSnapshot,
    KinetixSharedProps,
} from '@/types/kinetix';

/**
 * Live application-health metrics for <KinetixHealthStatus>: loads the latest
 * spatie/laravel-health results from the gated endpoint and polls on an interval
 * (from the shared `kinetix_health` config). Works gracefully without the package.
 */
export function useKinetixHealth() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/health`;
    const interval = page.props.kinetix_health?.poll ?? 30000;

    const snapshot = ref<KinetixHealthSnapshot | null>(null);
    const loading = ref(false);
    const failed = ref(false);

    let timer: ReturnType<typeof setInterval> | null = null;

    async function load(): Promise<void> {
        loading.value = true;

        try {
            snapshot.value = await kinetixFetch<KinetixHealthSnapshot>(base());
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
