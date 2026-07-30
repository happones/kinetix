import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixOnboarding, KinetixSharedProps } from '@/types/kinetix';

/**
 * Self-service onboarding checklist, talking to Kinetix's `onboarding`
 * endpoints. `complete` returns the refreshed state so the UI can re-render
 * progress without a second round-trip.
 */
export function useKinetixOnboarding() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/onboarding`;

    const state = ref<KinetixOnboarding | null>(null);
    const loading = ref(false);

    async function load(): Promise<void> {
        loading.value = true;

        try {
            state.value = await kinetixFetch<KinetixOnboarding>(base());
        } finally {
            loading.value = false;
        }
    }

    async function complete(step: string): Promise<void> {
        state.value = await kinetixFetch<KinetixOnboarding>(
            `${base()}/complete`,
            {
                method: 'POST',
                body: { step },
            },
        );
    }

    async function dismiss(): Promise<void> {
        await kinetixFetch(`${base()}/dismiss`, { method: 'POST' });

        if (state.value) {
            state.value = { ...state.value, dismissed: true };
        }
    }

    return { state, loading, load, complete, dismiss };
}
