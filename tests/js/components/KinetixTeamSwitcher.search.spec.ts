import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

const visitMock = vi.fn();
const teams = Array.from({ length: 3 }, (_, i) => ({
    id: i + 1,
    name: `Team ${i + 1}`,
    url: `/teams/${i + 1}/switch`,
    current: i === 0,
}));
const pageProps: { kinetix_teams: Record<string, unknown> } = {
    kinetix_teams: {},
};

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: pageProps }),
    router: { visit: (...a: unknown[]) => visitMock(...a) },
}));

const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    isKinetixAbort: (e: unknown) =>
        e instanceof DOMException && e.name === 'AbortError',
}));

import KinetixTeamSwitcher from '@/components/KinetixTeamSwitcher.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: {
        en: {
            kinetix: {
                teams_switch: 'Switch team',
                teams_select: 'Select a team',
                teams_new: 'New team',
                teams_search: 'Search teams',
                teams_search_empty: 'No teams found.',
                teams_search_results: 'Results: {count}',
                teams_showing: 'Showing {shown} of {total} teams',
            },
        },
    },
});

const base = {
    enabled: true,
    current: { id: 1, name: 'Team 1' },
    createUrl: '/teams/create',
    teams,
};

async function openSwitcher() {
    const w = mount(KinetixTeamSwitcher, {
        attachTo: document.body,
        global: { plugins: [i18n] },
    });
    await w.find('button').trigger('pointerdown', { button: 0 });
    await w.find('button').trigger('keydown', { key: 'Enter' });
    await flushPromises();
    await new Promise((r) => setTimeout(r, 0));

    return w;
}

const menu = () => document.body.querySelector('[role="menu"]') as HTMLElement;
const search = () =>
    document.body.querySelector(
        '[data-test="team-switcher-search"]',
    ) as HTMLInputElement | null;
const rowNames = () =>
    [...menu().querySelectorAll('[role="menuitem"] span.truncate')].map(
        (name) => name.textContent?.trim() ?? '',
    );

function type(value: string): void {
    const input = search()!;
    input.value = value;
    input.dispatchEvent(new Event('input'));
}

beforeEach(() => {
    fetchMock.mockReset();
    visitMock.mockReset();
});

afterEach(() => {
    vi.useRealTimers();
    document.body.innerHTML = '';
});

describe('KinetixTeamSwitcher without truncation', () => {
    it('renders the plain list: no search box, no count line', async () => {
        pageProps.kinetix_teams = { ...base };
        const w = await openSwitcher();

        expect(search()).toBeNull();
        expect(
            document.body.querySelector('[data-test="team-switcher-showing"]'),
        ).toBeNull();

        // Label, the rows, separator, create: nothing else in the menu.
        expect(
            [...menu().children].map(
                (el) => el.getAttribute('role') ?? el.tagName.toLowerCase(),
            ),
        ).toEqual([
            'div',
            'menuitem',
            'menuitem',
            'menuitem',
            'separator',
            'menuitem',
        ]);
        expect(rowNames()).toEqual(['Team 1', 'Team 2', 'Team 3']);
        expect(fetchMock).not.toHaveBeenCalled();
        w.unmount();
    });
});

describe('KinetixTeamSwitcher with a truncated list', () => {
    beforeEach(() => {
        pageProps.kinetix_teams = {
            ...base,
            truncated: true,
            total: 48,
            searchUrl: '/_kinetix/team-switcher/teams',
        };
    });

    it('opens on a labelled search box and says how many teams are shown', async () => {
        const w = await openSwitcher();

        const input = search()!;
        expect(input.getAttribute('aria-label')).toBe('Search teams');
        expect(document.activeElement).toBe(input);
        expect(
            document.body.querySelector('[data-test="team-switcher-showing"]')
                ?.textContent,
        ).toContain('Showing 3 of 48 teams');
        w.unmount();
    });

    it('debounces, aborts the previous request and lists the results, marking the current team', async () => {
        const signals: AbortSignal[] = [];
        fetchMock.mockImplementation(
            (_url: string, options: { signal: AbortSignal }) => {
                signals.push(options.signal);

                return new Promise((resolve, reject) => {
                    options.signal.addEventListener('abort', () =>
                        reject(new DOMException('stop', 'AbortError')),
                    );
                    setTimeout(
                        () =>
                            resolve({
                                teams: [
                                    {
                                        id: 1,
                                        name: 'Team 1',
                                        url: '/t/1',
                                        current: false,
                                    },
                                    {
                                        id: 31,
                                        name: 'Team 31',
                                        url: '/t/31',
                                        current: false,
                                    },
                                ],
                                truncated: false,
                            }),
                        100,
                    );
                });
            },
        );

        const w = await openSwitcher();
        vi.useFakeTimers();

        type('te');
        await vi.advanceTimersByTimeAsync(100);
        expect(fetchMock).not.toHaveBeenCalled();

        await vi.advanceTimersByTimeAsync(200);
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock.mock.calls[0][0]).toBe(
            '/_kinetix/team-switcher/teams?q=te',
        );

        // A new query while the first is in flight aborts it.
        type('team 3');
        await vi.advanceTimersByTimeAsync(250);
        expect(signals[0].aborted).toBe(true);
        expect(fetchMock.mock.calls[1][0]).toBe(
            '/_kinetix/team-switcher/teams?q=team%203',
        );

        await vi.advanceTimersByTimeAsync(150);
        await flushPromises();

        expect(rowNames()).toEqual(['Team 1', 'Team 31']);
        // `current` comes from the id, not the server.
        const current = menu().querySelector('[aria-current="true"]');
        expect(current?.textContent).toContain('Team 1');

        // The count is announced.
        await vi.advanceTimersByTimeAsync(20);
        expect(
            document.getElementById('kinetix-live-region')?.textContent,
        ).toBe('Results: 2');
        w.unmount();
    });

    it('shows the empty state for no match, and the server message on a throttle', async () => {
        fetchMock.mockResolvedValueOnce({ teams: [], truncated: false });
        const w = await openSwitcher();
        vi.useFakeTimers();

        type('zzz');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        expect(
            document.body.querySelector(
                '[data-test="team-switcher-search-empty"]',
            )?.textContent,
        ).toContain('No teams found.');

        fetchMock.mockRejectedValueOnce(
            Object.assign(
                new Error('Too many attempts. Try again in 9 seconds.'),
                { status: 429 },
            ),
        );
        type('zzzz');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        expect(
            document.body.querySelector(
                '[data-test="team-switcher-search-empty"]',
            )?.textContent,
        ).toContain('Too many attempts. Try again in 9 seconds.');

        // Clearing the box goes back to the shared list.
        type('');
        await flushPromises();
        expect(rowNames()).toEqual(['Team 1', 'Team 2', 'Team 3']);
        w.unmount();
    });

    it("keeps typed keys away from the menu's typeahead, and ArrowDown moves into the list", async () => {
        const w = await openSwitcher();
        const input = search()!;

        const keyEvent = (key: string) =>
            new KeyboardEvent('keydown', {
                key,
                bubbles: true,
                cancelable: true,
            });

        // A character never reaches the menu (its typeahead would move focus).
        const menuKeys: string[] = [];
        menu().addEventListener('keydown', (e) => menuKeys.push(e.key));
        input.dispatchEvent(keyEvent('t'));
        input.dispatchEvent(keyEvent(' '));
        expect(menuKeys).toEqual([]);
        expect(document.activeElement).toBe(input);

        const down = keyEvent('ArrowDown');
        input.dispatchEvent(down);
        expect(down.defaultPrevented).toBe(true);
        expect((document.activeElement as HTMLElement).textContent).toContain(
            'Team 1',
        );

        // ArrowUp on the first row goes back to the box.
        (document.activeElement as HTMLElement).dispatchEvent(
            keyEvent('ArrowUp'),
        );
        expect(document.activeElement).toBe(input);

        // Escape still reaches the menu (it closes it).
        input.dispatchEvent(keyEvent('Escape'));
        expect(menuKeys).toEqual(['Escape']);
        w.unmount();
    });

    it('switches to a team found by search', async () => {
        fetchMock.mockResolvedValueOnce({
            teams: [{ id: 31, name: 'Team 31', url: '/t/31', current: false }],
            truncated: false,
        });
        const w = await openSwitcher();
        vi.useFakeTimers();

        type('31');
        await vi.advanceTimersByTimeAsync(300);
        await flushPromises();

        (
            [...menu().querySelectorAll('[role="menuitem"]')].find((item) =>
                item.textContent?.includes('Team 31'),
            ) as HTMLElement
        ).click();

        expect(visitMock).toHaveBeenCalledWith('/t/31');
        w.unmount();
    });
});
