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
     *   teamUrl('/projects')  → '/projects'        (teams off, or the team
     *                                               rides in the host)
     *
     * Idempotent: a path that already starts with the team key is returned
     * unchanged, so passing a server-generated URL through it is safe.
     */
    function teamUrl(path: string): string {
        const clean = path.startsWith('/') ? path : `/${path}`;
        const key = currentTeamKey.value;

        if (
            key === null ||
            key === '' ||
            page.props.kinetix_config?.team_in_host
        ) {
            return clean;
        }

        const prefix = `/${key}`;

        return clean === prefix || clean.startsWith(`${prefix}/`)
            ? clean
            : `${prefix}${clean}`;
    }

    /**
     * Switch to a team by visiting its switch URL. With `keepPage`, a page
     * under the team's URL segment (`/acme/products/7`) reopens for the new
     * team (`/beta/products/7`) once the switch lands, instead of wherever
     * the switch route redirects.
     */
    function switchTeam(
        team: KinetixTeamOption,
        options: { keepPage?: boolean } = {},
    ): void {
        if (team.current || !team.url) {
            return;
        }

        // With the team in the host the other team lives on another origin,
        // which an Inertia visit (an XHR) cannot follow: load it outright.
        if (isOtherOrigin(team.url)) {
            window.location.assign(team.url);

            return;
        }

        const samePage =
            options.keepPage && typeof window !== 'undefined'
                ? samePageFor(team, currentTeamKey.value)
                : null;

        const method = state.value?.switchMethod ?? 'get';

        if (samePage === null && method === 'get') {
            router.visit(team.url);

            return;
        }

        router.visit(team.url, {
            ...(method === 'get' ? {} : { method }),
            ...(samePage === null
                ? {}
                : {
                      onSuccess: () =>
                          router.visit(samePage, { replace: true }),
                  }),
        });
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

/**
 * The current page with the team's URL segment swapped for another team's,
 * or null when the page doesn't carry the current team in its path.
 */
function samePageFor(
    team: KinetixTeamOption,
    currentKey: string | number | null,
): string | null {
    if (currentKey === null || team.key == null) {
        return null;
    }

    const segment = `/${encodeURIComponent(String(currentKey))}`;
    const { pathname, search, hash } = window.location;

    if (pathname !== segment && !pathname.startsWith(`${segment}/`)) {
        return null;
    }

    const rest = pathname.slice(segment.length);

    return `/${encodeURIComponent(String(team.key))}${rest}${search}${hash}`;
}

/**
 * Whether a URL points at another origin than the page's.
 */
function isOtherOrigin(url: string): boolean {
    if (typeof window === 'undefined') {
        return false;
    }

    try {
        return (
            new URL(url, window.location.href).origin !== window.location.origin
        );
    } catch {
        return false;
    }
}
