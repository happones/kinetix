import { beforeEach, describe, expect, it, vi } from 'vitest';

const visit = vi.fn();
const props: Record<string, unknown> = {};

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props }),
    router: {
        visit: (...args: unknown[]) => visit(...args),
    },
}));

import { useKinetixTeams } from '@/composables/useKinetixTeams';

describe('useKinetixTeams', () => {
    beforeEach(() => {
        visit.mockClear();
        for (const key of Object.keys(props)) {
            delete props[key];
        }
    });

    describe('team-aware links', () => {
        it('prefixes app paths with the resolved team route key', () => {
            props.kinetix_config = { team: 'acme' };

            const { teamUrl, currentTeamKey } = useKinetixTeams();

            expect(currentTeamKey.value).toBe('acme');
            expect(teamUrl('/projects')).toBe('/acme/projects');
            expect(teamUrl('projects')).toBe('/acme/projects');
        });

        it('is a no-op when teams are off', () => {
            props.kinetix_config = { team: null };

            const { teamUrl, currentTeamKey } = useKinetixTeams();

            expect(currentTeamKey.value).toBeNull();
            expect(teamUrl('/projects')).toBe('/projects');
        });

        it('never double-prefixes an already team-scoped path', () => {
            props.kinetix_config = { team: 'acme' };

            const { teamUrl } = useKinetixTeams();

            expect(teamUrl('/acme/projects')).toBe('/acme/projects');
            expect(teamUrl('/acme')).toBe('/acme');
        });

        it('does not confuse a team-like prefix with the team segment', () => {
            props.kinetix_config = { team: 'acme' };

            const { teamUrl } = useKinetixTeams();

            // `/acme-corp` is a different path, not the `acme` team.
            expect(teamUrl('/acme-corp/projects')).toBe(
                '/acme/acme-corp/projects',
            );
        });

        it('uses the route key, which is not the numeric team id', () => {
            props.kinetix_config = { team: 'acme' };
            props.kinetix_teams = {
                enabled: true,
                teams: [],
                current: { id: 7, name: 'Acme' },
                createUrl: null,
            };

            const { teamUrl, current } = useKinetixTeams();

            expect(current.value?.id).toBe(7);
            expect(teamUrl('/projects')).toBe('/acme/projects');
        });

        it('falls back safely when the config prop is missing', () => {
            const { teamUrl, currentTeamKey } = useKinetixTeams();

            expect(currentTeamKey.value).toBeNull();
            expect(teamUrl('/projects')).toBe('/projects');
        });
    });

    describe('switching', () => {
        it('visits the host-provided URL', () => {
            const { switchTeam } = useKinetixTeams();

            switchTeam({
                id: 2,
                name: 'Beta',
                url: '/teams/2/switch',
                current: false,
            });

            expect(visit).toHaveBeenCalledWith('/teams/2/switch');
        });

        it('ignores the current team and options without a URL', () => {
            const { switchTeam } = useKinetixTeams();

            switchTeam({
                id: 1,
                name: 'Alpha',
                url: '/teams/1/switch',
                current: true,
            });
            switchTeam({ id: 3, name: 'Gamma', url: null, current: false });

            expect(visit).not.toHaveBeenCalled();
        });
    });
});
