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

import KinetixCombobox from '@/components/KinetixCombobox.vue';
import { resetKinetixNotificationRoutingState } from '@/composables/useKinetixNotificationRouting';
import KinetixNotificationRouting from '@/components/KinetixNotificationRouting.vue';
import KinetixSelect from '@/components/KinetixSelect.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: {} } },
});

const route = (id: number, over: Record<string, unknown> = {}) => ({
    id,
    type: 'orders',
    audienceType: 'group',
    audienceId: '1',
    audienceLabel: 'Support leads',
    channels: ['mail', 'database'],
    ...over,
});

const index = () => ({
    channels: [
        { key: 'mail', label: 'Email' },
        { key: 'database', label: 'In-app' },
        { key: 'broadcast', label: 'Push' },
    ],
    types: [
        {
            key: 'orders',
            label: 'Order updates',
            channels: ['mail', 'database'],
            mandatory: [],
            requires: null,
            routes: [
                route(1),
                route(2, {
                    audienceType: 'member',
                    audienceId: 'App\\Models\\User|9',
                    audienceLabel: null,
                }),
                route(3, {
                    audienceType: 'eligible',
                    audienceId: null,
                    audienceLabel: 'Everyone',
                }),
            ],
        },
        {
            key: 'security',
            label: 'Security alerts',
            channels: ['mail', 'database'],
            mandatory: ['mail'],
            requires: null,
            routes: [],
        },
    ],
});

const audiences = {
    members: [{ id: 'App\\Models\\User|1', name: 'Ada' }],
    roles: [{ id: 5, name: 'Admin' }],
    groups: [{ id: 1, name: 'Support leads' }],
};

/** Answers each endpoint the way the server would, by verb and URL prefix. */
function serve(routes: Record<string, (body?: unknown) => unknown>): void {
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

const base = 'GET /_kinetix/notification-routing';
const mountIt = async () => {
    const w = mount(KinetixNotificationRouting, {
        attachTo: document.body,
        global: { plugins: [i18n] },
    });
    await flushPromises();

    return w;
};

beforeEach(() => {
    resetKinetixNotificationRoutingState();
    fetchMock.mockReset();
    serve({
        [`${base}/audiences`]: () => audiences,
        [base]: index,
    });
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('KinetixNotificationRouting', () => {
    it('renders a section per type with its audiences and channels', async () => {
        const w = await mountIt();

        expect(w.get('[data-test="routing-type-orders"]').text()).toContain(
            'Order updates',
        );
        expect(w.get('[data-test="routing-type-orders"]').text()).toContain(
            'Support leads',
        );
        // Channel icons carry their labels for assistive tech.
        expect(w.get('[data-test="routing-route-1"]').text()).toContain(
            'Email',
        );
        expect(w.get('[data-test="routing-route-1"]').text()).toContain(
            'In-app',
        );
        expect(w.get('[data-test="routing-route-3"]').text()).toContain(
            'notification_routing_kind_eligible',
        );
        expect(w.findAll('h3').map((h) => h.text())).toEqual([
            'Order updates',
            'Security alerts',
        ]);
    });

    it('marks an audience that no longer exists as removed', async () => {
        const w = await mountIt();

        expect(w.get('[data-test="routing-route-2"]').text()).toContain(
            'notification_routing_removed',
        );
    });

    it('shows the mandatory channels of a type, never editable', async () => {
        const w = await mountIt();
        const box = w.get('[data-test="routing-type-security"]');

        expect(box.get('[data-test="routing-mandatory"]').text()).toContain(
            'Email',
        );
        expect(
            w
                .get('[data-test="routing-type-orders"]')
                .find('[data-test="routing-mandatory"]')
                .exists(),
        ).toBe(false);
    });

    it('shows an empty state when the app registered no type', async () => {
        serve({
            [`${base}/audiences`]: () => audiences,
            [base]: () => ({ channels: [], types: [] }),
        });
        const w = await mountIt();

        expect(w.text()).toContain('notification_routing_empty_title');
    });

    it('shows an error with a retry when the load fails', async () => {
        serve({
            [`${base}/audiences`]: () => audiences,
            [base]: () => {
                throw new Error('down');
            },
        });
        const w = await mountIt();

        expect(w.get('[role="alert"]').text()).toContain(
            'notification_routing_load_failed',
        );
    });

    it('adds an audience: limited to the type channels, posting the chosen ones', async () => {
        const created = route(10, {
            audienceType: 'role',
            audienceId: '5',
            audienceLabel: 'Admin',
            channels: ['database'],
        });
        const post = vi.fn(() => ({ route: created }));
        serve({
            [`${base}/audiences`]: () => audiences,
            [base]: index,
            'POST /_kinetix/notification-routing/routes': post,
        });
        const w = await mountIt();

        await w.get('[data-test="routing-add"]').trigger('click');
        await flushPromises();

        // Only the type's own channels are offered (no Push).
        const labels = [
            ...document.querySelectorAll(
                '#kinetix-notification-route [role="group"] label',
            ),
        ].map((l) => l.textContent?.trim());
        expect(labels).toEqual(['Email', 'In-app']);

        w.findComponent(KinetixSelect).vm.$emit('update:value', 'role');
        await flushPromises();
        w.findComponent(KinetixCombobox).vm.$emit('update:value', '5');
        // Uncheck Email.
        (
            document.querySelector(
                '#kinetix-notification-route [role="checkbox"]',
            ) as HTMLElement
        ).click();
        await flushPromises();

        (
            document.querySelector(
                '[data-test="notification-route-submit"]',
            ) as HTMLElement
        ).click();
        await flushPromises();

        expect(post).toHaveBeenCalledWith({
            type: 'orders',
            audience_type: 'role',
            audience_id: '5',
            channels: ['database'],
        });
        expect(w.get('[data-test="routing-type-orders"]').text()).toContain(
            'Admin',
        );
        expect(
            document.querySelector('#kinetix-notification-route'),
        ).toBeNull();
    });

    it('asks for an audience before posting', async () => {
        const w = await mountIt();
        await w.get('[data-test="routing-add"]').trigger('click');
        await flushPromises();

        (
            document.querySelector(
                '[data-test="notification-route-submit"]',
            ) as HTMLElement
        ).click();
        await flushPromises();

        expect(document.body.textContent).toContain(
            'notification_routing_audience_required',
        );
        expect(fetchMock.mock.calls.some((c) => c[1]?.method === 'POST')).toBe(
            false,
        );
    });

    it('hides the role option when the app has no roles', async () => {
        serve({
            [`${base}/audiences`]: () => ({ ...audiences, roles: [] }),
            [base]: index,
        });
        const w = await mountIt();
        await w.get('[data-test="routing-add"]').trigger('click');
        await flushPromises();

        const options = w
            .findComponent(KinetixSelect)
            .props('options') as Array<{
            value: string;
        }>;
        expect(options.map((o) => o.value)).toEqual([
            'member',
            'group',
            'eligible',
        ]);
    });

    it('shows a server 422 inline and keeps the dialog open', async () => {
        serve({
            [`${base}/audiences`]: () => audiences,
            [base]: index,
            'POST /_kinetix/notification-routing/routes': () => {
                throw Object.assign(new Error('Invalid'), {
                    status: 422,
                    errors: { channels: ['Not allowed for this type.'] },
                });
            },
        });
        const w = await mountIt();
        await w.get('[data-test="routing-add"]').trigger('click');
        await flushPromises();
        w.findComponent(KinetixSelect).vm.$emit('update:value', 'eligible');
        await flushPromises();

        (
            document.querySelector(
                '[data-test="notification-route-submit"]',
            ) as HTMLElement
        ).click();
        await flushPromises();

        const alert = document.querySelector(
            '#kinetix-notification-route [role="alert"]',
        );
        expect(alert?.textContent).toContain('Not allowed for this type.');
        expect(
            document.querySelector('#kinetix-notification-route'),
        ).not.toBeNull();
    });

    it('edits the channels of an audience with a PUT', async () => {
        const put = vi.fn(() => ({
            route: route(1, { channels: ['database'] }),
        }));
        serve({
            [`${base}/audiences`]: () => audiences,
            [base]: index,
            'PUT /_kinetix/notification-routing/routes/1': put,
        });
        const w = await mountIt();
        await w
            .get('[data-test="routing-route-1"] [data-test="routing-edit"]')
            .trigger('click');
        await flushPromises();

        (
            document.querySelector(
                '#kinetix-notification-route [role="checkbox"]',
            ) as HTMLElement
        ).click();
        await flushPromises();
        (
            document.querySelector(
                '[data-test="notification-route-submit"]',
            ) as HTMLElement
        ).click();
        await flushPromises();

        expect(put).toHaveBeenCalledWith({ channels: ['database'] });
    });

    it('removes an audience only after a confirmation', async () => {
        const del = vi.fn(() => ({ status: 'success' }));
        serve({
            [`${base}/audiences`]: () => audiences,
            [base]: index,
            'DELETE /_kinetix/notification-routing/routes/1': del,
        });
        const w = await mountIt();
        await w
            .get('[data-test="routing-route-1"] [data-test="routing-remove"]')
            .trigger('click');
        await flushPromises();

        expect(del).not.toHaveBeenCalled();
        const confirm = [...document.querySelectorAll('button')].find(
            (b) =>
                b.textContent?.includes('notification_routing_remove') &&
                !b.getAttribute('aria-label'),
        ) as HTMLElement;
        // The confirm button is the modal's submit, labelled "Remove".
        expect(confirm).toBeTruthy();
        confirm.click();
        await flushPromises();

        expect(del).toHaveBeenCalled();
        expect(w.find('[data-test="routing-route-1"]').exists()).toBe(false);
    });

    it('previews recipients with the reason each skipped person is left out', async () => {
        serve({
            [`${base}/audiences`]: () => audiences,
            [`${base}/recipients?type=orders`]: () => ({
                recipients: [
                    { id: 1, name: 'Ada', channels: ['mail'], reason: null },
                ],
                skipped: [
                    { id: 2, name: 'Linus', channels: [], reason: 'opted_out' },
                    { id: 3, name: 'Ken', channels: [], reason: 'ineligible' },
                    {
                        id: 4,
                        name: 'Alan',
                        channels: [],
                        reason: 'no_channels',
                    },
                ],
                hasRules: true,
                truncated: true,
            }),
            [base]: index,
        });
        const w = await mountIt();

        await w
            .get(
                '[data-test="routing-type-orders"] [data-test="routing-preview"]',
            )
            .trigger('click');
        await flushPromises();

        const preview = w.get('[data-test="notification-routing-preview"]');
        expect(preview.text()).toContain('Ada');
        expect(preview.get('[data-test="preview-skipped"]').text()).toContain(
            'notification_routing_reason_opted_out',
        );
        expect(preview.text()).toContain(
            'notification_routing_reason_ineligible',
        );
        expect(preview.text()).toContain(
            'notification_routing_reason_no_channels',
        );
        expect(preview.find('[data-test="preview-truncated"]').exists()).toBe(
            true,
        );
        expect(preview.find('[data-test="preview-no-rules"]').exists()).toBe(
            false,
        );
        // The results are announced politely.
        expect(preview.find('[aria-live="polite"]').exists()).toBe(true);
    });

    it('tells the admin when the type has no rules', async () => {
        serve({
            [`${base}/audiences`]: () => audiences,
            [`${base}/recipients?type=security`]: () => ({
                recipients: [],
                skipped: [],
                hasRules: false,
                truncated: false,
            }),
            [base]: index,
        });
        const w = await mountIt();

        await w
            .get(
                '[data-test="routing-type-security"] [data-test="routing-preview"]',
            )
            .trigger('click');
        await flushPromises();

        expect(w.find('[data-test="preview-no-rules"]').exists()).toBe(true);
        expect(w.text()).toContain('notification_routing_preview_no_rules');
        expect(w.text()).not.toContain('notification_routing_preview_nobody');
    });

    const serveSecurity = (
        type: Record<string, unknown>,
        recipients: Record<string, unknown>,
    ) => {
        const data = index();
        data.types[1] = { ...data.types[1], ...type } as never;
        serve({
            [`${base}/audiences`]: () => audiences,
            [`${base}/recipients?type=security`]: () => ({
                recipients: [],
                skipped: [],
                skippedCount: 0,
                hasRules: false,
                truncated: false,
                fellBack: false,
                ...recipients,
            }),
            [base]: () => data,
        });
    };

    const security = (w: Awaited<ReturnType<typeof mountIt>>) =>
        w.get('[data-test="routing-type-security"]');

    const openPreview = async (w: Awaited<ReturnType<typeof mountIt>>) => {
        await security(w).get('[data-test="routing-preview"]').trigger('click');
        await flushPromises();
    };

    it('explains the no-routes state per fallback: description, default recipients, neutral', async () => {
        serveSecurity(
            { fallback: 'Sent to the HR team.', hasFallback: true },
            {},
        );
        let w = await mountIt();
        expect(security(w).text()).toContain('Sent to the HR team.');

        serveSecurity({ fallback: null, hasFallback: true }, {});
        resetKinetixNotificationRoutingState();
        w = await mountIt();
        expect(security(w).text()).toContain(
            'notification_routing_no_routes_fallback',
        );

        serveSecurity({ fallback: null, hasFallback: false }, {});
        resetKinetixNotificationRoutingState();
        w = await mountIt();
        expect(security(w).text()).toContain('notification_routing_no_routes');
        expect(security(w).text()).not.toContain('_no_routes_fallback');
    });

    it('lists the default recipients under the fallback copy when none was chosen', async () => {
        serveSecurity(
            { fallback: 'The HR team.', hasFallback: true },
            {
                fellBack: true,
                recipients: [{ id: 'u|1', name: 'Ada', channels: ['mail'] }],
            },
        );
        const w = await mountIt();
        await openPreview(w);

        expect(w.get('[data-test="preview-fallback"]').text()).toContain(
            'notification_routing_preview_fallback',
        );
        expect(w.get('[data-test="preview-fallback"]').text()).toContain(
            'The HR team.',
        );
        expect(w.get('[data-test="preview-recipients"]').text()).toContain(
            'Ada',
        );
        expect(w.find('[data-test="preview-no-rules"]').exists()).toBe(false);
    });

    it('shows the description, not "nobody", when the type declares no fallback', async () => {
        serveSecurity(
            { fallback: 'Goes to the owner.', hasFallback: false },
            {},
        );
        const w = await mountIt();
        await openPreview(w);

        expect(w.get('[data-test="preview-no-rules"]').text()).toBe(
            'Goes to the owner.',
        );
        expect(w.find('[data-test="preview-recipients"]').exists()).toBe(false);
        expect(w.text()).not.toContain('notification_routing_preview_nobody');
    });

    it('warns that nobody would get it when the declared fallback resolves to no one', async () => {
        serveSecurity(
            { fallback: 'Goes to the owner.', hasFallback: true },
            {},
        );
        const w = await mountIt();
        await openPreview(w);

        const alert = w.get('[data-test="preview-fallback-empty"]');
        expect(alert.text()).toContain('notification_routing_preview_nobody');
        expect(alert.text()).toContain('Goes to the owner.');
        expect(w.find('[data-test="preview-no-rules"]').exists()).toBe(false);
    });

    it('says nobody would be notified when rules exist but match no one', async () => {
        serveSecurity({}, { hasRules: true });
        const w = await mountIt();
        await openPreview(w);

        expect(w.text()).toContain('notification_routing_preview_nobody');
    });

    it('labels role options with the server label, falling back to the name', async () => {
        serve({
            [`${base}/audiences`]: () => ({
                ...audiences,
                roles: [
                    { id: 'admin', name: 'Administrador' },
                    { id: 'qa', name: 'qa-lead' },
                ],
            }),
            [base]: index,
        });
        const w = await mountIt();
        await w
            .get('[data-test="routing-type-orders"] [data-test="routing-add"]')
            .trigger('click');
        await flushPromises();
        w.findComponent(KinetixSelect).vm.$emit('update:value', 'role');
        await flushPromises();

        const options = (
            w.findComponent(KinetixCombobox).props('options') as Array<{
                label: string;
            }>
        ).map((o) => o.label);
        expect(options).toContain('Administrador');
        expect(options).toContain('qa-lead');
    });

    it('feeds the member picker from the server search, with opaque string ids', async () => {
        const search = vi.fn(() => ({
            members: [{ id: 'App\\Models\\User|7', name: 'Grace' }],
        }));
        serve({
            [`${base}/audiences?search=`]: search,
            [`${base}/audiences`]: () => audiences,
            [base]: index,
        });
        const w = await mountIt();
        await w.get('[data-test="routing-add"]').trigger('click');
        await flushPromises();

        const fetchOptions = w
            .findComponent(KinetixCombobox)
            .props('fetchOptions') as (
            q: string,
        ) => Promise<Array<{ value: string; label: string }>>;

        expect(await fetchOptions('gra ce')).toEqual([
            { value: 'App\\Models\\User|7', label: 'Grace' },
        ]);
        expect(fetchMock).toHaveBeenLastCalledWith(
            '/_kinetix/notification-routing/audiences?search=gra%20ce',
            { signal: expect.any(AbortSignal) },
        );
    });

    it('skips the member list on mount (the member picker searches the server)', async () => {
        await mountIt();

        expect(fetchMock).toHaveBeenCalledWith(
            '/_kinetix/notification-routing/audiences?members=0',
        );
    });

    it('counts everyone left out, not only the sample, and says how many are not listed', async () => {
        serve({
            [`${base}/audiences`]: () => audiences,
            [`${base}/recipients?type=orders`]: () => ({
                recipients: [],
                skipped: [
                    {
                        id: 'App\\Models\\User|2',
                        name: 'Linus',
                        channels: [],
                        reason: 'opted_out',
                    },
                ],
                skippedCount: 120,
                hasRules: true,
                truncated: false,
            }),
            [base]: index,
        });
        const w = await mountIt();
        await w
            .get(
                '[data-test="routing-type-orders"] [data-test="routing-preview"]',
            )
            .trigger('click');
        await flushPromises();

        const more = w.get('[data-test="preview-skipped-more"]');
        expect(more.text()).toContain('notification_routing_preview_more');
        expect(w.get('[data-test="preview-skipped"]').text()).toContain(
            'Linus',
        );
    });

    it('shows the left-out block when only the count is known', async () => {
        serve({
            [`${base}/audiences`]: () => audiences,
            [`${base}/recipients?type=orders`]: () => ({
                recipients: [],
                skipped: [],
                skippedCount: 4,
                hasRules: true,
                truncated: false,
            }),
            [base]: index,
        });
        const w = await mountIt();
        await w
            .get(
                '[data-test="routing-type-orders"] [data-test="routing-preview"]',
            )
            .trigger('click');
        await flushPromises();

        expect(w.find('[data-test="preview-skipped"]').exists()).toBe(true);
    });

    it("returns focus to the type's preview button when the panel is closed", async () => {
        serve({
            [`${base}/audiences`]: () => audiences,
            [`${base}/recipients?type=orders`]: () => ({
                recipients: [],
                skipped: [],
                skippedCount: 0,
                hasRules: true,
                truncated: false,
            }),
            [base]: index,
        });
        const w = await mountIt();
        const button = w.get(
            '[data-test="routing-type-orders"] [data-test="routing-preview"]',
        );
        await button.trigger('click');
        await flushPromises();

        await w
            .get(
                '[data-test="notification-routing-preview"] button[aria-label]',
            )
            .trigger('click');
        await flushPromises();

        expect(
            w.find('[data-test="notification-routing-preview"]').exists(),
        ).toBe(false);
        expect(document.activeElement).toBe(button.element);
    });

    it('refreshes an open preview after a rule of that type is removed', async () => {
        const recipients = vi.fn(() => ({
            recipients: [],
            skipped: [],
            skippedCount: 0,
            hasRules: true,
            truncated: false,
        }));
        serve({
            [`${base}/audiences`]: () => audiences,
            [`${base}/recipients?type=orders`]: recipients,
            'DELETE /_kinetix/notification-routing/routes/1': () => ({
                status: 'success',
            }),
            [base]: index,
        });
        const w = await mountIt();
        await w
            .get(
                '[data-test="routing-type-orders"] [data-test="routing-preview"]',
            )
            .trigger('click');
        await flushPromises();
        expect(recipients).toHaveBeenCalledTimes(1);

        await w
            .get('[data-test="routing-route-1"] [data-test="routing-remove"]')
            .trigger('click');
        await flushPromises();
        (
            [...document.querySelectorAll('button')].find(
                (b) =>
                    b.textContent?.trim() ===
                    'kinetix.notification_routing_remove',
            ) as HTMLElement
        ).click();
        await flushPromises();

        expect(recipients).toHaveBeenCalledTimes(2);
    });

    it('folds a nested 422 key into its field and keeps the dialog open', async () => {
        serve({
            [`${base}/audiences`]: () => audiences,
            [base]: index,
            'POST /_kinetix/notification-routing/routes': () => {
                throw Object.assign(new Error('Invalid'), {
                    status: 422,
                    errors: { 'channels.0': ['Not a channel of this type.'] },
                });
            },
        });
        const w = await mountIt();
        await w.get('[data-test="routing-add"]').trigger('click');
        await flushPromises();
        w.findComponent(KinetixSelect).vm.$emit('update:value', 'eligible');
        await flushPromises();
        (
            document.querySelector(
                '[data-test="notification-route-submit"]',
            ) as HTMLElement
        ).click();
        await flushPromises();

        const group = document.querySelector(
            '#kinetix-notification-route [role="group"]',
        );
        expect(group?.getAttribute('aria-describedby')).toBe(
            'kinetix-notification-route-channels-error',
        );
        expect(
            document.getElementById('kinetix-notification-route-channels-error')
                ?.textContent,
        ).toContain('Not a channel of this type.');
    });

    it('explains a failure that is not a validation error without a raw HTTP message', async () => {
        serve({
            [`${base}/audiences`]: () => audiences,
            [base]: index,
            'POST /_kinetix/notification-routing/routes': () => {
                throw Object.assign(new Error('HTTP error! status: 404'), {
                    status: 404,
                });
            },
        });
        const w = await mountIt();
        await w.get('[data-test="routing-add"]').trigger('click');
        await flushPromises();
        w.findComponent(KinetixSelect).vm.$emit('update:value', 'eligible');
        await flushPromises();
        (
            document.querySelector(
                '[data-test="notification-route-submit"]',
            ) as HTMLElement
        ).click();
        await flushPromises();

        const text = document.querySelector(
            '#kinetix-notification-route',
        )?.textContent;
        expect(text).toContain('kinetix.notification_routing_save_failed');
        expect(text).not.toContain('HTTP error');
    });

    it('does not say there is nobody to choose when the member picker is remote', async () => {
        const w = await mountIt();
        await w.get('[data-test="routing-add"]').trigger('click');
        await flushPromises();

        expect(
            document.querySelector('#kinetix-notification-route')?.textContent,
        ).not.toContain('notification_routing_no_audience_options');
        expect(w.findComponent(KinetixCombobox).exists()).toBe(true);
    });

    it('toasts a translated message, not the server text, when a removal hits a missing record', async () => {
        serve({
            [`${base}/audiences`]: () => audiences,
            [base]: index,
            'DELETE /_kinetix/notification-routing/routes/1': () => {
                throw Object.assign(
                    new Error('No query results for model [X] 1'),
                    { status: 404 },
                );
            },
        });
        const w = await mountIt();
        await w
            .get('[data-test="routing-route-1"] [data-test="routing-remove"]')
            .trigger('click');
        await flushPromises();
        (
            [...document.querySelectorAll('button')].find(
                (b) =>
                    b.textContent?.trim() ===
                    'kinetix.notification_routing_remove',
            ) as HTMLElement
        ).click();
        await flushPromises();

        const { toast } = await import('vue-sonner');
        expect(toast.error).toHaveBeenCalledWith(
            'kinetix.notification_routing_failed',
        );
    });
});
