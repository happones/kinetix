import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';
import { defineComponent, h } from 'vue';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));
vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));
const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import KinetixNotificationGroups from '@/components/KinetixNotificationGroups.vue';
import KinetixNotificationRouting from '@/components/KinetixNotificationRouting.vue';
import { resetKinetixNotificationRoutingState } from '@/composables/useKinetixNotificationRouting';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: {} } },
});

const base = '/_kinetix/notification-routing';
const index = () => ({
    channels: [{ key: 'mail', label: 'Email' }],
    types: [
        {
            key: 'orders',
            label: 'Orders',
            channels: ['mail'],
            mandatory: [],
            requires: null,
            routes: [
                {
                    id: 1,
                    type: 'orders',
                    audienceType: 'group',
                    audienceId: '1',
                    audienceLabel: 'Leads',
                    channels: ['mail'],
                },
            ],
        },
    ],
});

let recipientsCalls = 0;
let groups = [{ id: 1, name: 'Leads', description: null, members: [] }];

function serve(): void {
    fetchMock.mockImplementation(
        async (url: string, options: { method?: string } = {}) => {
            const method = options.method ?? 'GET';

            if (method === 'DELETE' && url === `${base}/groups/1`) {
                groups = [];

                return { status: 'success' };
            }

            if (method === 'POST' && url === `${base}/groups`) {
                const group = {
                    id: 2,
                    name: 'New crew',
                    description: null,
                    members: [],
                };
                groups = [...groups, group];

                return { group };
            }

            if (url.startsWith(`${base}/audiences`)) {
                return {
                    members: [],
                    membersComplete: true,
                    roles: [],
                    groups: groups.map((g) => ({ id: g.id, name: g.name })),
                };
            }

            if (url === `${base}/groups`) {
                return { groups };
            }

            if (url.startsWith(`${base}/recipients`)) {
                recipientsCalls++;

                return {
                    recipients: [],
                    skipped: [],
                    skippedCount: 0,
                    hasRules: true,
                    truncated: false,
                };
            }

            if (url === base) {
                return index();
            }

            throw new Error(`Unexpected ${method} ${url}`);
        },
    );
}

const Page = defineComponent({
    render: () =>
        h('div', [h(KinetixNotificationRouting), h(KinetixNotificationGroups)]),
});

beforeEach(() => {
    resetKinetixNotificationRoutingState();
    recipientsCalls = 0;
    groups = [{ id: 1, name: 'Leads', description: null, members: [] }];
    fetchMock.mockReset();
    serve();
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('routing and groups mounted together', () => {
    it('drops the rules of a group deleted on the groups screen', async () => {
        const w = mount(Page, {
            attachTo: document.body,
            global: { plugins: [i18n] },
        });
        await flushPromises();
        expect(w.find('[data-test="routing-route-1"]').exists()).toBe(true);

        await w
            .get('[data-test="group-1"] [data-test="group-delete"]')
            .trigger('click');
        await flushPromises();
        (
            [...document.querySelectorAll('button')].find(
                (b) => b.textContent?.trim() === 'kinetix.delete',
            ) as HTMLElement
        ).click();
        await flushPromises();

        expect(w.find('[data-test="group-1"]').exists()).toBe(false);
        expect(w.find('[data-test="routing-route-1"]').exists()).toBe(false);
    });

    it('offers a group created on the groups screen in the routing picker', async () => {
        const w = mount(Page, {
            attachTo: document.body,
            global: { plugins: [i18n] },
        });
        await flushPromises();

        await w.get('[data-test="group-new"]').trigger('click');
        await flushPromises();
        const name = document.querySelector(
            '#kinetix-notification-group-name',
        ) as HTMLInputElement;
        name.value = 'New crew';
        name.dispatchEvent(new Event('input'));
        await flushPromises();
        (
            document.querySelector(
                '[data-test="notification-group-submit"]',
            ) as HTMLElement
        ).click();
        await flushPromises();

        const routing = w.findComponent(KinetixNotificationRouting);
        const shared = (
            routing.vm as unknown as {
                $: {
                    setupState: {
                        audiences: { groups: Array<{ name: string }> };
                    };
                };
            }
        ).$.setupState.audiences;
        expect(shared.groups.map((g) => g.name)).toEqual(['Leads', 'New crew']);
    });

    it('refreshes an open routing preview when a group changes on the groups screen', async () => {
        const w = mount(Page, {
            attachTo: document.body,
            global: { plugins: [i18n] },
        });
        await flushPromises();
        await w.get('[data-test="routing-preview"]').trigger('click');
        await flushPromises();
        expect(recipientsCalls).toBe(1);

        await w
            .get('[data-test="group-1"] [data-test="group-delete"]')
            .trigger('click');
        await flushPromises();
        (
            [...document.querySelectorAll('button')].find(
                (b) => b.textContent?.trim() === 'kinetix.delete',
            ) as HTMLElement
        ).click();
        await flushPromises();

        expect(recipientsCalls).toBe(2);
    });
});
