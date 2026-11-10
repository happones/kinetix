import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import KinetixCheckboxList from '@/components/KinetixCheckboxList.vue';
import { resetKinetixNotificationRoutingState } from '@/composables/useKinetixNotificationRouting';
import KinetixNotificationGroups from '@/components/KinetixNotificationGroups.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: {} } },
});

const leads = {
    id: 1,
    name: 'Support leads',
    description: 'Escalations',
    members: [{ id: 'App\\Models\\User|1', name: 'Ada' }],
};

const base = '/_kinetix/notification-routing';

function serve(
    extra: Record<string, (body?: unknown) => unknown> = {},
    groups: unknown[] = [leads],
): void {
    const routes: Record<string, (body?: unknown) => unknown> = {
        [`GET ${base}/audiences`]: () => ({
            members: [
                { id: 'App\\Models\\User|1', name: 'Ada' },
                { id: 'App\\Models\\User|2', name: 'Linus' },
            ],
            roles: [],
            groups: [],
        }),
        [`GET ${base}/groups`]: () => ({ groups }),
        ...extra,
    };

    fetchMock.mockImplementation(
        async (
            url: string,
            options: { method?: string; body?: unknown } = {},
        ) => {
            const key = `${options.method ?? 'GET'} ${url}`;
            const match = Object.keys(routes).find((r) => key.startsWith(r));

            if (!match) {
                throw new Error(`Unexpected ${key}`);
            }

            return routes[match](options.body);
        },
    );
}

const mountIt = async () => {
    const w = mount(KinetixNotificationGroups, {
        attachTo: document.body,
        global: { plugins: [i18n] },
    });
    await flushPromises();

    return w;
};

const click = (selector: string): void =>
    (document.querySelector(selector) as HTMLElement).click();

beforeEach(() => {
    resetKinetixNotificationRoutingState();
    fetchMock.mockReset();
});
afterEach(() => {
    document.body.innerHTML = '';
});

describe('KinetixNotificationGroups', () => {
    it('lists the groups with their member count', async () => {
        serve();
        const w = await mountIt();

        expect(w.get('[data-test="group-1"]').text()).toContain(
            'Support leads',
        );
        expect(w.get('[data-test="group-1"]').text()).toContain('Escalations');
        expect(w.get('[data-test="group-1"]').text()).toContain(
            'notification_routing_groups_member_count',
        );
    });

    it('shows an empty state with a way to create the first group', async () => {
        serve({}, []);
        const w = await mountIt();

        expect(w.text()).toContain('notification_routing_groups_empty_title');
    });

    it('creates a group with the name and the picked members', async () => {
        const created = {
            id: 2,
            name: 'On call',
            description: null,
            members: [],
        };
        const post = vi.fn(() => ({ group: created }));
        serve({ [`POST ${base}/groups`]: post });
        const w = await mountIt();

        await w.get('[data-test="group-new"]').trigger('click');
        await flushPromises();
        const name = document.querySelector(
            '#kinetix-notification-group-name',
        ) as HTMLInputElement;
        name.value = 'On call';
        name.dispatchEvent(new Event('input'));
        w.findComponent(KinetixCheckboxList).vm.$emit('update:value', [
            'App\\Models\\User|2',
        ]);
        await flushPromises();
        click('[data-test="notification-group-submit"]');
        await flushPromises();

        expect(post).toHaveBeenCalledWith({
            name: 'On call',
            description: null,
            member_ids: ['App\\Models\\User|2'],
        });
        expect(w.text()).toContain('On call');
    });

    it('shows a taken name from the server beside the field', async () => {
        serve({
            [`POST ${base}/groups`]: () => {
                throw Object.assign(new Error('Invalid'), {
                    status: 422,
                    errors: { name: ['That name is taken.'] },
                });
            },
        });
        const w = await mountIt();
        await w.get('[data-test="group-new"]').trigger('click');
        await flushPromises();

        const name = document.querySelector(
            '#kinetix-notification-group-name',
        ) as HTMLInputElement;
        name.value = 'Leads';
        name.dispatchEvent(new Event('input'));
        await flushPromises();
        click('[data-test="notification-group-submit"]');
        await flushPromises();

        const error = document.querySelector(
            '#kinetix-notification-group-name-error',
        );
        expect(error?.textContent).toContain('That name is taken.');
        expect(
            document
                .querySelector('#kinetix-notification-group-name')
                ?.getAttribute('aria-describedby'),
        ).toBe('kinetix-notification-group-name-error');
    });

    it('edits a group, replacing its membership', async () => {
        const put = vi.fn(() => ({ group: { ...leads, name: 'Seniors' } }));
        serve({ [`PUT ${base}/groups/1`]: put });
        const w = await mountIt();

        await w
            .get('[data-test="group-1"] [data-test="group-edit"]')
            .trigger('click');
        await flushPromises();

        expect(
            (
                document.querySelector(
                    '#kinetix-notification-group-name',
                ) as HTMLInputElement
            ).value,
        ).toBe('Support leads');
        click('[data-test="notification-group-submit"]');
        await flushPromises();

        expect(put).toHaveBeenCalledWith({
            name: 'Support leads',
            description: 'Escalations',
            member_ids: ['App\\Models\\User|1'],
        });
    });

    it('warns that its rules go too before deleting, and deletes on confirm', async () => {
        const del = vi.fn(() => ({ status: 'success' }));
        serve({ [`DELETE ${base}/groups/1`]: del });
        const w = await mountIt();

        await w
            .get('[data-test="group-1"] [data-test="group-delete"]')
            .trigger('click');
        await flushPromises();

        expect(del).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain(
            'notification_routing_groups_delete_description',
        );
        const confirm = [...document.querySelectorAll('button')].find(
            (b) => b.textContent?.trim() === 'kinetix.delete',
        ) as HTMLElement;
        confirm.click();
        await flushPromises();

        expect(del).toHaveBeenCalled();
        expect(w.find('[data-test="group-1"]').exists()).toBe(false);
    });

    describe('member search', () => {
        const linus = { id: 'App\\Models\\User|200', name: 'Linus' };

        afterEach(() => {
            vi.useRealTimers();
        });

        it('searches the server once, debounced, and keeps a selected member outside the page', async () => {
            const search = vi.fn((url: string) => ({
                members: url.includes('search=Lin') ? [linus] : [],
            }));
            const put = vi.fn(() => ({ group: leads }));
            serve({
                [`GET ${base}/audiences?search=`]: ((_body: unknown) =>
                    null) as never,
                [`PUT ${base}/groups/1`]: put,
            });
            // Route searches through a spy that sees the URL.
            const inner = fetchMock.getMockImplementation()!;
            fetchMock.mockImplementation(
                async (url: string, options?: { method?: string }) =>
                    url.includes('audiences?search=')
                        ? search(url)
                        : inner(url, options),
            );
            const w = await mountIt();

            await w
                .get('[data-test="group-1"] [data-test="group-edit"]')
                .trigger('click');
            await flushPromises();

            vi.useFakeTimers();
            const input = document.querySelector(
                '[data-test="group-member-search"]',
            ) as HTMLInputElement;
            for (const text of ['L', 'Li', 'Lin']) {
                input.value = text;
                input.dispatchEvent(new Event('input'));
                await vi.advanceTimersByTimeAsync(100);
            }
            expect(search).not.toHaveBeenCalled();
            await vi.advanceTimersByTimeAsync(300);
            await flushPromises();
            vi.useRealTimers();

            expect(search).toHaveBeenCalledTimes(1);
            expect(search.mock.calls[0][0]).toBe(
                `${base}/audiences?search=Lin`,
            );

            // Ada (already in the group) is not in this page but stays listed and checked.
            const labels = [
                ...document.querySelectorAll(
                    '#kinetix-notification-group [role="group"] label:has([role="checkbox"])',
                ),
            ].map((l) => l.textContent?.trim());
            expect(labels).toEqual(['Linus', 'Ada']);

            w.findComponent(KinetixCheckboxList).vm.$emit('update:value', [
                'App\\Models\\User|1',
                'App\\Models\\User|200',
            ]);
            await flushPromises();
            click('[data-test="notification-group-submit"]');
            await flushPromises();

            expect(put).toHaveBeenCalledWith({
                name: 'Support leads',
                description: 'Escalations',
                member_ids: ['App\\Models\\User|1', 'App\\Models\\User|200'],
            });
        });
    });

    it('shows a nested member error under the members and a generic failure as a form error', async () => {
        serve({
            [`POST ${base}/groups`]: () => {
                throw Object.assign(new Error('Invalid'), {
                    status: 422,
                    errors: { 'member_ids.0': ['Not a member of this team.'] },
                });
            },
        });
        const w = await mountIt();
        await w.get('[data-test="group-new"]').trigger('click');
        await flushPromises();
        const name = document.querySelector(
            '#kinetix-notification-group-name',
        ) as HTMLInputElement;
        name.value = 'X';
        name.dispatchEvent(new Event('input'));
        await flushPromises();
        click('[data-test="notification-group-submit"]');
        await flushPromises();

        expect(
            document
                .querySelector('#kinetix-notification-group [role="group"]')
                ?.getAttribute('aria-describedby'),
        ).toBe('kinetix-notification-group-members-error');
        expect(
            document.getElementById('kinetix-notification-group-members-error')
                ?.textContent,
        ).toContain('Not a member of this team.');

        serve({
            [`POST ${base}/groups`]: () => {
                throw Object.assign(new Error('HTTP error! status: 404'), {
                    status: 404,
                });
            },
        });
        click('[data-test="notification-group-submit"]');
        await flushPromises();
        const form = document.querySelector(
            '#kinetix-notification-group',
        )?.textContent;
        expect(form).toContain('kinetix.notification_routing_save_failed');
        expect(form).not.toContain('HTTP error');
        // Never put under the name field.
        expect(
            document.getElementById('kinetix-notification-group-name-error'),
        ).toBeNull();
    });

    it('filters locally when the loaded members are the whole team', async () => {
        const search = vi.fn();
        serve({
            [`GET ${base}/audiences`]: () => ({
                members: [
                    { id: 'App\\Models\\User|1', name: 'Ada' },
                    { id: 'App\\Models\\User|2', name: 'Linus' },
                ],
                membersComplete: true,
                roles: [],
                groups: [],
            }),
            [`GET ${base}/audiences?search=`]: search,
        });
        const w = await mountIt();
        await w.get('[data-test="group-new"]').trigger('click');
        await flushPromises();

        const input = document.querySelector(
            '[data-test="group-member-search"]',
        ) as HTMLInputElement;
        input.value = 'lin';
        input.dispatchEvent(new Event('input'));
        await flushPromises();

        const labels = [
            ...document.querySelectorAll(
                '#kinetix-notification-group label:has([role="checkbox"])',
            ),
        ].map((l) => l.textContent?.trim());
        expect(labels).toEqual(['Linus']);
        expect(search).not.toHaveBeenCalled();
    });
});
