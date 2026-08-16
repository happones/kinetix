import { usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixOnboarding, KinetixSharedProps } from '@/types/kinetix';

/**
 * Self-service onboarding checklist, talking to Kinetix's `onboarding`
 * endpoints. `complete` returns the refreshed state so the UI can re-render
 * progress without a second round-trip.
 *
 * The state normally arrives on the page payload (`kinetix_onboarding`), so
 * mounting the checklist — including the sidebar variant, which sits in the
 * layout and is therefore mounted on every page — costs no request at all.
 * `load()` only reaches for the network when that prop is absent, i.e. when the
 * host turned `onboarding.share` off. Anything written here (a ticked step, a
 * dismissal) wins over the prop until the next Inertia response refreshes it.
 */
export function useKinetixOnboarding() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/onboarding`;

    const shared = computed<KinetixOnboarding | null>(
        () => page.props.kinetix_onboarding ?? null,
    );

    const local = ref<KinetixOnboarding | null>(null);
    const state = computed<KinetixOnboarding | null>(
        () => local.value ?? shared.value,
    );

    const loading = ref(false);

    async function load(force = false): Promise<void> {
        if (state.value && !force) {
            return;
        }

        loading.value = true;

        try {
            local.value = await kinetixFetch<KinetixOnboarding>(base());
        } finally {
            loading.value = false;
        }
    }

    async function complete(step: string): Promise<void> {
        local.value = await kinetixFetch<KinetixOnboarding>(
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
            local.value = { ...state.value, dismissed: true };
        }
    }

    return { state, loading, load, complete, dismiss };
}
