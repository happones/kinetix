import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

export interface KinetixTeam {
    id: number | string;
    slug: string;
    name: string;
}

export function useKinetixTeam() {
    const page = usePage<{ currentTeam?: KinetixTeam | null }>();

    const currentTeam = computed(() => page.props.currentTeam ?? null);

    function teamUrl<T extends { url: string }>(
        route: (team: string) => T,
        fallback = '/',
    ) {
        return computed(() => {
            const slug = currentTeam.value?.slug;

            return slug ? route(slug).url : fallback;
        });
    }

    return {
        currentTeam,
        teamUrl,
    };
}
