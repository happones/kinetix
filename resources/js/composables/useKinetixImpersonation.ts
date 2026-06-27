import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixSharedProps } from '@/types';

/**
 * Reads the `kinetix_impersonation` shared prop and ends an impersonation
 * session. `leave()` is an Inertia visit (DELETE) so the page reloads as the
 * original admin once the server swaps the user back.
 */
export function useKinetixImpersonation() {
    const page = usePage<KinetixSharedProps>();

    const state = computed(
        () => page.props.kinetix_impersonation ?? { active: false },
    );

    const active = computed(() => !!state.value.active);
    const impersonatedName = computed(() => state.value.user?.name ?? null);

    function leave(): void {
        router.delete(`/${kinetixRoutePrefix(page)}/impersonate`);
    }

    return { active, impersonatedName, leave };
}
