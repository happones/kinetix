import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type { ComputedRef } from 'vue';
import type { KinetixSharedProps } from '@/types';

/**
 * Frontend mirror of the backend feature flags. Reads the `kinetix_features`
 * shared prop (resolved server-side for the current scope), so the SPA gates UI
 * by the same flag names the server enforces. Reactive — updates when Inertia
 * replaces the page props.
 */
export function useKinetixFeature() {
    const page = usePage<KinetixSharedProps>();

    const flags: ComputedRef<Record<string, boolean>> = computed(
        () => page.props.kinetix_features ?? {},
    );

    const active = (name: string): boolean => flags.value[name] === true;
    const inactive = (name: string): boolean => !active(name);

    return { active, inactive, flags };
}
