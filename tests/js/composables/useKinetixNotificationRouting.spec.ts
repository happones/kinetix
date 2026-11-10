import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));
const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', async () => {
    const { ref } = await import('vue');
    const prefix = ref('_kinetix');

    return {
        kinetixFetch: (...args: unknown[]) => fetchMock(...args),
        // Reactive, so a spec can switch the team under a mounted screen.
        kinetixRoutePrefix: () => prefix.value,
        __prefix: prefix,
    };
});

import * as http from '@/composables/useKinetixHttp';
import {
    notificationRoutingErrors,
    notificationRoutingMessage,
    resetKinetixNotificationRoutingState,
    useKinetixNotificationRouting,
} from '@/composables/useKinetixNotificationRouting';

const prefix = (http as unknown as { __prefix: { value: string } }).__prefix;

function routing(unmountable = false) {
    let api!: ReturnType<typeof useKinetixNotificationRouting>;
    const wrapper = mount(
        defineComponent({
            setup() {
                api = useKinetixNotificationRouting();

                return () => h('div');
            },
        }),
    );

    return unmountable ? Object.assign(api, { wrapper }) : api;
}

const route = (id: number, over: Record<string, unknown> = {}) => ({
    id,
    type: 'orders',
    audienceType: 'member',
    audienceId: '1',
    audienceLabel: 'Ada',
    channels: ['mail'],
    ...over,
});

const index = {
    channels: [{ key: 'mail', label: 'Email' }],
    types: [
        {
            key: 'orders',
            label: 'Orders',
            channels: ['mail'],
            mandatory: [],
            requires: null,
            routes: [route(1)],
        },
    ],
};

beforeEach(() => {
    prefix.value = '_kinetix';
    resetKinetixNotificationRoutingState();
    fetchMock.mockReset();
});

describe('useKinetixNotificationRouting', () => {
    it('loads the types and channels from the prefixed endpoint', async () => {
        fetchMock.mockResolvedValueOnce(index);
        const api = routing();
        await api.load();

        expect(fetchMock).toHaveBeenCalledWith(
            '/_kinetix/notification-routing',
        );
        expect(api.types.value).toHaveLength(1);
        expect(api.channels.value[0].label).toBe('Email');
        expect(api.loaded.value).toBe(true);
    });

    it('keeps the newest load when an older one lands late', async () => {
        let resolveFirst!: (v: unknown) => void;
        fetchMock
            .mockImplementationOnce(
                () => new Promise((resolve) => (resolveFirst = resolve)),
            )
            .mockResolvedValueOnce({ ...index, types: [] });
        const api = routing();

        const first = api.load();
        await api.load();
        resolveFirst(index);
        await first;

        expect(api.types.value).toEqual([]);
        expect(api.loading.value).toBe(false);
    });

    it('flags a failed load', async () => {
        fetchMock.mockRejectedValueOnce(new Error('boom'));
        const api = routing();
        await api.load();

        expect(api.loadFailed.value).toBe(true);
        expect(api.loaded.value).toBe(false);
    });

    it('adds a rule to its type, replaces it on an upsert and removes it', async () => {
        fetchMock.mockResolvedValueOnce(index);
        const api = routing();
        await api.load();

        fetchMock.mockResolvedValueOnce({
            route: route(2, { audienceType: 'role', audienceLabel: 'Admin' }),
        });
        await api.addRoute({
            type: 'orders',
            audience_type: 'role',
            audience_id: '3',
            channels: ['mail'],
        });
        expect(fetchMock).toHaveBeenLastCalledWith(
            '/_kinetix/notification-routing/routes',
            expect.objectContaining({ method: 'POST' }),
        );
        expect(api.types.value[0].routes).toHaveLength(2);

        fetchMock.mockResolvedValueOnce({ route: route(2, { channels: [] }) });
        await api.updateRoute(api.types.value[0].routes[1], []);
        expect(api.types.value[0].routes).toHaveLength(2);
        expect(api.types.value[0].routes[1].channels).toEqual([]);

        fetchMock.mockResolvedValueOnce({ status: 'success' });
        await api.removeRoute(api.types.value[0].routes[1]);
        expect(api.types.value[0].routes.map((r) => r.id)).toEqual([1]);
    });

    it('lets a rejected write reach the caller with its field errors', async () => {
        const error = Object.assign(new Error('Invalid'), {
            status: 422,
            errors: { channels: ['Pick one'] },
        });
        fetchMock.mockRejectedValueOnce(error);
        const api = routing();

        await expect(
            api.addRoute({
                type: 'orders',
                audience_type: 'eligible',
                channels: [],
            }),
        ).rejects.toBe(error);
        expect(notificationRoutingErrors(error, 'fallback')).toEqual({
            channels: 'Pick one',
        });
        expect(
            notificationRoutingErrors(
                Object.assign(new Error('x'), { status: 403 }),
                'fallback',
            ),
        ).toEqual({ form: 'x' });
        // A raw HTTP failure is never shown as is.
        expect(
            notificationRoutingErrors(
                new Error('HTTP error! status: 404'),
                'fallback',
            ),
        ).toEqual({ form: 'fallback' });
        // A nested key folds into its field.
        expect(
            notificationRoutingErrors(
                {
                    errors: {
                        'channels.0': ['Bad channel'],
                        'member_ids.3': ['Bad member'],
                        member_ids: ['Whole list'],
                    },
                },
                'fallback',
            ),
        ).toEqual({ channels: 'Bad channel', member_ids: 'Bad member' });
    });

    it('previews recipients and drops a response that lands after another request', async () => {
        const api = routing();
        let resolveFirst!: (v: unknown) => void;
        const people = (name: string) => ({
            recipients: [{ id: 1, name, channels: ['mail'], reason: null }],
            skipped: [],
            hasRules: true,
            truncated: false,
        });
        fetchMock
            .mockImplementationOnce(
                () => new Promise((resolve) => (resolveFirst = resolve)),
            )
            .mockResolvedValueOnce(people('Second'));

        const first = api.previewRecipients('orders');
        await api.previewRecipients('invoices');
        resolveFirst(people('First'));
        await first;

        expect(fetchMock).toHaveBeenLastCalledWith(
            '/_kinetix/notification-routing/recipients?type=invoices',
        );
        expect(api.preview.value?.recipients[0].name).toBe('Second');
        expect(api.previewLoading.value).toBe(false);
    });

    it('ignores a preview that finishes after it was closed', async () => {
        const api = routing();
        let resolve!: (v: unknown) => void;
        fetchMock.mockImplementationOnce(
            () => new Promise((r) => (resolve = r)),
        );

        const pending = api.previewRecipients('orders');
        api.closePreview();
        resolve({
            recipients: [],
            skipped: [],
            hasRules: true,
            truncated: false,
        });
        await pending;

        expect(api.preview.value).toBeNull();
        expect(api.previewType.value).toBeNull();
    });

    it('creates, updates and deletes groups, taking a deleted group out of the rules', async () => {
        fetchMock.mockResolvedValueOnce({
            ...index,
            types: [
                {
                    ...index.types[0],
                    routes: [
                        route(1),
                        route(2, { audienceType: 'group', audienceId: '7' }),
                    ],
                },
            ],
        });
        const api = routing();
        await api.load();

        const group = { id: 7, name: 'Leads', description: null, members: [] };
        fetchMock.mockResolvedValueOnce({ group });
        await api.createGroup({ name: 'Leads', member_ids: [] });
        expect(api.groups.value).toEqual([group]);

        fetchMock.mockResolvedValueOnce({
            group: { ...group, name: 'Seniors' },
        });
        await api.updateGroup(group, { name: 'Seniors' });
        expect(fetchMock).toHaveBeenLastCalledWith(
            '/_kinetix/notification-routing/groups/7',
            expect.objectContaining({ method: 'PUT' }),
        );
        expect(api.groups.value[0].name).toBe('Seniors');

        fetchMock.mockResolvedValueOnce({ status: 'success' });
        await api.deleteGroup(group);
        expect(api.groups.value).toEqual([]);
        expect(api.types.value[0].routes.map((r) => r.id)).toEqual([1]);
    });

    it('encodes an opaque id in a URL path and keeps it a string', async () => {
        const api = routing();
        const group = {
            id: 'a|b/c',
            name: 'G',
            description: null,
            members: [{ id: 'App\\Models\\User|5', name: 'Ada' }],
        };
        fetchMock.mockResolvedValueOnce({ group });
        await api.updateGroup(group, { member_ids: ['App\\Models\\User|5'] });

        expect(fetchMock.mock.calls[0][0]).toBe(
            '/_kinetix/notification-routing/groups/a%7Cb%2Fc',
        );
        expect(api.groups.value[0].members[0].id).toBe('App\\Models\\User|5');
    });

    it('searches members on the server, and a superseded search resolves to the newest result', async () => {
        const api = routing();
        let resolveFirst!: (v: unknown) => void;
        const ada = { id: 'App\\Models\\User|1', name: 'Ada' };
        const linus = { id: 'App\\Models\\User|2', name: 'Linus' };
        fetchMock
            .mockImplementationOnce(
                () => new Promise((resolve) => (resolveFirst = resolve)),
            )
            .mockResolvedValueOnce({ members: [linus] });

        const first = api.searchMembers('a d');
        const second = api.searchMembers('li');
        resolveFirst({ members: [ada] });

        expect(fetchMock.mock.calls[0][0]).toBe(
            '/_kinetix/notification-routing/audiences?search=a%20d',
        );
        expect(await first).toEqual([linus]);
        expect(await second).toEqual([linus]);
    });

    it('loads the audiences with roles empty when the app has none', async () => {
        fetchMock.mockResolvedValueOnce({
            members: [{ id: 1, name: 'Ada' }],
            roles: [],
            groups: [],
        });
        const api = routing();
        await api.loadAudiences();
        await flushPromises();

        expect(api.audiences.value.members).toHaveLength(1);
        expect(api.audiences.value.roles).toEqual([]);
    });

    const ref = 'App\\Models\\User|5';
    const group = (members: unknown[] = []) => ({
        id: 3,
        name: 'Leads',
        description: null,
        members,
    });

    it('adds members to a group and removes one by its encoded ref', async () => {
        const api = routing();
        const g = group();
        fetchMock.mockResolvedValueOnce({
            group: group([{ id: ref, name: 'Ada' }]),
        });
        await api.addMembers(g, [ref]);
        expect(fetchMock).toHaveBeenLastCalledWith(
            '/_kinetix/notification-routing/groups/3/members',
            { method: 'POST', body: { member_ids: [ref] } },
        );
        expect(api.groups.value[0].members).toHaveLength(1);

        fetchMock.mockResolvedValueOnce({ group: group() });
        await api.removeMember(g, ref);
        expect(fetchMock).toHaveBeenLastCalledWith(
            '/_kinetix/notification-routing/groups/3/members/App%5CModels%5CUser%7C5',
            { method: 'DELETE' },
        );
        expect(api.groups.value[0].members).toEqual([]);
    });

    it('keeps the loading flags of the rules and of the groups apart', async () => {
        const api = routing();
        fetchMock
            .mockRejectedValueOnce(new Error('down'))
            .mockResolvedValueOnce({ groups: [group()] });

        await Promise.all([api.load(), api.loadGroups()]);

        expect(api.loadFailed.value).toBe(true);
        expect(api.loaded.value).toBe(false);
        expect(api.groupsLoadFailed.value).toBe(false);
        expect(api.groupsLoaded.value).toBe(true);
        expect(api.groupsLoading.value).toBe(false);
    });

    it('shares rules, audiences and groups between two screens of the same team', async () => {
        const a = routing();
        const b = routing();
        fetchMock.mockResolvedValueOnce({
            ...index,
            types: [
                {
                    ...index.types[0],
                    routes: [
                        route(1, { audienceType: 'group', audienceId: '3' }),
                    ],
                },
            ],
        });
        await a.load();
        expect(b.types.value[0].routes).toHaveLength(1);

        // A group created on one screen is pickable on the other.
        fetchMock.mockResolvedValueOnce({ group: group() });
        await b.createGroup({ name: 'Leads' });
        expect(a.audiences.value.groups).toEqual([{ id: 3, name: 'Leads' }]);

        // Renamed: the rules that point at it show the new name.
        fetchMock.mockResolvedValueOnce({
            group: { ...group(), name: 'Seniors' },
        });
        await b.updateGroup(group(), { name: 'Seniors' });
        expect(a.types.value[0].routes[0].audienceLabel).toBe('Seniors');

        // Deleted: gone from the picker and from the rules.
        fetchMock.mockResolvedValueOnce({ status: 'success' });
        await b.deleteGroup(group());
        expect(a.audiences.value.groups).toEqual([]);
        expect(a.types.value[0].routes).toEqual([]);
    });

    it('skips the members with members=0 and keeps the ones already loaded', async () => {
        const api = routing();
        fetchMock.mockResolvedValueOnce({
            members: [{ id: ref, name: 'Ada' }],
            membersComplete: true,
            roles: [],
            groups: [],
        });
        await api.loadAudiences();
        fetchMock.mockResolvedValueOnce({
            members: [],
            membersComplete: false,
            roles: [{ id: 1, name: 'Admin' }],
            groups: [],
        });
        await api.loadAudiences({ members: false });

        expect(fetchMock).toHaveBeenLastCalledWith(
            '/_kinetix/notification-routing/audiences?members=0',
        );
        expect(api.audiences.value.members).toHaveLength(1);
        expect(api.audiences.value.membersComplete).toBe(true);
        expect(api.audiences.value.roles).toHaveLength(1);

        // Already loaded: not fetched again.
        fetchMock.mockClear();
        await api.loadAudiences();
        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('swallows the failure of a superseded member search', async () => {
        const api = routing();
        let rejectFirst!: (e: Error) => void;
        const linus = { id: 'App\\Models\\User|2', name: 'Linus' };
        fetchMock
            .mockImplementationOnce(
                () => new Promise((_r, reject) => (rejectFirst = reject)),
            )
            .mockResolvedValueOnce({ members: [linus] });

        const first = api.searchMembers('a');
        const second = api.searchMembers('li');
        rejectFirst(new Error('network'));

        expect(await first).toEqual([linus]);
        expect(await second).toEqual([linus]);
    });

    it('shows server text only where it is written for people', () => {
        const http404 = Object.assign(
            new Error('No query results for model [X] 7'),
            { status: 404 },
        );
        const http422 = Object.assign(new Error('The name is taken.'), {
            status: 422,
        });
        const http403 = Object.assign(new Error('Not allowed.'), {
            status: 403,
        });
        const http500 = Object.assign(new Error('Server Error'), {
            status: 500,
        });

        expect(notificationRoutingMessage(http404, 'fb')).toBe('fb');
        expect(notificationRoutingMessage(http500, 'fb')).toBe('fb');
        expect(
            notificationRoutingMessage(new Error('Failed to fetch'), 'fb'),
        ).toBe('fb');
        expect(notificationRoutingMessage(http422, 'fb')).toBe(
            'The name is taken.',
        );
        expect(notificationRoutingMessage(http403, 'fb')).toBe('Not allowed.');
        expect(notificationRoutingErrors(http404, 'fb')).toEqual({
            form: 'fb',
        });
    });

    it('keeps teams apart and starts fresh after the last screen unmounts', async () => {
        const a = routing(true);
        fetchMock.mockResolvedValueOnce(index);
        await a.load();
        expect(a.types.value).toHaveLength(1);

        // Another team's screen (another prefix) does not see it.
        prefix.value = '_t2';
        const b = routing(true);
        expect(b.types.value).toEqual([]);
        prefix.value = '_kinetix';

        // The last screen of a team leaves: the next one starts empty.
        a.wrapper.unmount();
        const c = routing();
        expect(c.types.value).toEqual([]);
    });

    it("moves to the new team's state when the prefix changes under a mounted screen", async () => {
        const api = routing();
        fetchMock.mockResolvedValueOnce(index);
        await api.load();
        expect(api.types.value).toHaveLength(1);

        prefix.value = '_t2';
        await flushPromises();
        expect(api.types.value).toEqual([]);
        expect(api.loaded.value).toBe(false);

        fetchMock.mockResolvedValueOnce({ ...index, types: [] });
        await api.load();
        expect(fetchMock).toHaveBeenLastCalledWith('/_t2/notification-routing');
    });

    it('does not let a slow members-less audiences response undo a group change', async () => {
        const api = routing();
        let resolveSlow!: (v: unknown) => void;
        fetchMock.mockImplementationOnce(
            () => new Promise((resolve) => (resolveSlow = resolve)),
        );
        const slow = api.loadAudiences({ members: false });

        fetchMock.mockResolvedValueOnce({
            group: { id: 9, name: 'New', description: null, members: [] },
        });
        await api.createGroup({ name: 'New' });
        resolveSlow({
            members: [],
            membersComplete: false,
            roles: [],
            groups: [],
        });
        await slow;

        expect(api.audiences.value.groups).toEqual([{ id: 9, name: 'New' }]);
    });
});
