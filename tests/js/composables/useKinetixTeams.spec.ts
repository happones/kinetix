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

    describe('switching while keeping the page', () => {
        const beta = {
            id: 2,
            key: 'beta',
            name: 'Beta',
            url: '/teams/beta/switch',
            current: false,
        };

        it('reopens the same page for the new team once the switch lands', () => {
            props.kinetix_config = { team: 'acme' };
            window.history.pushState({}, '', '/acme/products/7?tab=stock#top');

            useKinetixTeams().switchTeam(beta, { keepPage: true });

            expect(visit).toHaveBeenCalledWith(
                '/teams/beta/switch',
                expect.any(Object),
            );
            visit.mock.calls[0][1].onSuccess();
            expect(visit).toHaveBeenLastCalledWith(
                '/beta/products/7?tab=stock#top',
                { replace: true },
            );
        });

        it('visits a POST-only switch route with POST', () => {
            props.kinetix_config = { team: 'acme' };
            props.kinetix_teams = { switchMethod: 'post' };
            window.history.pushState({}, '', '/settings/profile');

            useKinetixTeams().switchTeam(beta);

            expect(visit).toHaveBeenCalledWith('/teams/beta/switch', {
                method: 'post',
            });
        });

        it('leaves a page outside the team to the switch route', () => {
            props.kinetix_config = { team: 'acme' };
            window.history.pushState({}, '', '/settings/profile');

            useKinetixTeams().switchTeam(beta, { keepPage: true });

            expect(visit).toHaveBeenCalledWith('/teams/beta/switch');
        });

        it('does not keep the page unless asked', () => {
            props.kinetix_config = { team: 'acme' };
            window.history.pushState({}, '', '/acme/products');

            useKinetixTeams().switchTeam(beta);

            expect(visit).toHaveBeenCalledWith('/teams/beta/switch');
        });
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

    describe('team in the host', () => {
        it('leaves app paths alone: the host already names the team', () => {
            props.kinetix_config = { team: 'acme', team_in_host: true };

            const { teamUrl } = useKinetixTeams();

            expect(teamUrl('/projects')).toBe('/projects');
        });

        it('loads another team outright when it lives on another origin', () => {
            const assign = vi.fn();
            vi.stubGlobal('location', {
                ...window.location,
                href: 'http://acme.example.test/projects',
                origin: 'http://acme.example.test',
                assign,
            });

            const { switchTeam } = useKinetixTeams();

            switchTeam({
                id: 2,
                name: 'Beta',
                url: 'http://beta.example.test/dashboard',
                current: false,
            });

            expect(assign).toHaveBeenCalledWith(
                'http://beta.example.test/dashboard',
            );
            expect(visit).not.toHaveBeenCalled();

            vi.unstubAllGlobals();
        });
    });
});
