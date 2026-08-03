import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import type {
    KinetixSharedProps,
    KinetixTeamOption,
    KinetixTeamsState,
} from '@/types/kinetix';

/**
 * Team switcher + team-aware link building.
 *
 * Two concerns share this composable because both answer "which team is this
 * page for?":
 *
 *  - **Switching** reads the `kinetix_teams` prop (requires
 *    `kinetix.team_switcher.enabled`) and visits the host-provided URL —
 *    Kinetix stays model-agnostic about what switching means.
 *  - **Linking** reads the resolved team route key from `kinetix_config.team`,
 *    available whenever `kinetix.teams` is on, switcher or not. Use
 *    `teamUrl()` for in-app links instead of interpolating the team yourself:
 *    the segment carries the team's ROUTE key (a slug or uuid when the model
 *    defines one), which is NOT the `id` exposed on a team option.
 */
export function useKinetixTeams() {
    const page = usePage<KinetixSharedProps>();

    const state = computed<KinetixTeamsState | undefined>(
        () => page.props.kinetix_teams,
    );
    const teams = computed<KinetixTeamOption[]>(() => state.value?.teams ?? []);
    const current = computed(() => state.value?.current ?? null);
    const createUrl = computed(() => state.value?.createUrl ?? null);

    /**
     * The active team's route key — the `{current_team}` segment the server
     * resolved for this request. Null when teams are off.
     */
    const currentTeamKey = computed<string | number | null>(
        () => page.props.kinetix_config?.team ?? null,
    );

    /**
     * Prefix an app path with the active team segment.
     *
     *   teamUrl('/projects')  → '/acme/projects'   (teams on)
     *   teamUrl('/projects')  → '/projects'        (teams off)
     *
     * Idempotent: a path that already starts with the team key is returned
     * unchanged, so passing a server-generated URL through it is safe.
     */
    function teamUrl(path: string): string {
        const clean = path.startsWith('/') ? path : `/${path}`;
        const key = currentTeamKey.value;

        if (key === null || key === '') {
            return clean;
        }

        const prefix = `/${key}`;

        return clean === prefix || clean.startsWith(`${prefix}/`)
            ? clean
            : `${prefix}${clean}`;
    }

    function switchTeam(team: KinetixTeamOption): void {
        if (team.current || !team.url) {
            return;
        }

        router.visit(team.url);
    }

    return {
        teams,
        current,
        currentTeamKey,
        createUrl,
        teamUrl,
        switchTeam,
    };
}
