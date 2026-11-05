import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import { useKinetixPolling } from '@/composables/useKinetixPolling';
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

    const polling = useKinetixPolling(load, { interval });
    const start = (): void => polling.start();
    const stop = (): void => polling.stop();

    return { snapshot, loading, failed, load, start, stop };
}
