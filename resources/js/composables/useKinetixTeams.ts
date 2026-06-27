import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type {
    KinetixSharedProps,
    KinetixTeamOption,
    KinetixTeamsState,
} from '@/types';

/**
 * Team switcher: read the user's teams + current team from the shared
 * `kinetix_teams` prop and switch by visiting the host-provided URL. Kinetix
 * stays model-agnostic — switching is whatever route your app configured.
 */
export function useKinetixTeams() {
    const page = usePage<KinetixSharedProps>();

    const state = computed<KinetixTeamsState | undefined>(
        () => page.props.kinetix_teams,
    );
    const teams = computed<KinetixTeamOption[]>(() => state.value?.teams ?? []);
    const current = computed(() => state.value?.current ?? null);
    const createUrl = computed(() => state.value?.createUrl ?? null);

    function switchTeam(team: KinetixTeamOption): void {
        if (team.current || !team.url) {
            return;
        }

        router.visit(team.url);
    }

    return { teams, current, createUrl, switchTeam };
}
