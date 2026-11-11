import { usePage } from '@inertiajs/vue3';
import {
    computed,
    getCurrentScope,
    onScopeDispose,
    ref,
    shallowRef,
    watch,
} from 'vue';
import type { Ref } from 'vue';
import {
    KINETIX_SUPERSEDED,
    createKinetixLatest,
    kinetixAnySignal,
} from '@/composables/kinetixLatest';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type {
    KinetixNotificationAudience,
    KinetixNotificationAudiences,
    KinetixNotificationAudienceType,
    KinetixNotificationGroup,
    KinetixNotificationRecipients,
    KinetixNotificationRoute,
    KinetixNotificationRoutingType,
    KinetixSharedProps,
} from '@/types/kinetix';

export interface KinetixNotificationRouteInput {
    type: string;
    audience_type: KinetixNotificationAudienceType;
    /** A member's opaque ref (`{modelType}|{key}`), a role's or a group's id. */
    audience_id?: string | null;
    channels: string[];
}

export interface KinetixNotificationGroupInput {
    name?: string;
    description?: string | null;
    /** Opaque member refs, as strings. */
    member_ids?: string[];
}

/**
 * What every screen of one team shares: the rules, the people a rule can point
 * at and the groups. A group deleted from the groups screen therefore leaves
 * the routing screen at once, with the rules that pointed at it.
 */
interface SharedState {
    types: Ref<KinetixNotificationRoutingType[]>;
    channels: Ref<Array<{ key: string; label: string }>>;
    audiences: Ref<KinetixNotificationAudiences>;
    groups: Ref<KinetixNotificationGroup[]>;
    /** Whether `audiences.members` has been fetched (a routing screen skips them). */
    membersLoaded: boolean;
    /** Bumped by every group change: an older audiences response must not undo it. */
    groupsVersion: number;
    /** Per kind of audiences request (with and without members), so neither drops the other. */
    audiencesRequest: { full: number; light: number };
    users: number;
}

const stores = new Map<string, SharedState>();

const emptyAudiences = (): KinetixNotificationAudiences => ({
    members: [],
    membersComplete: false,
    roles: [],
    groups: [],
});

function acquire(key: string): SharedState {
    let store = stores.get(key);

    if (!store) {
        store = {
            types: ref([]),
            channels: ref([]),
            audiences: ref(emptyAudiences()),
            groups: ref([]),
            membersLoaded: false,
            groupsVersion: 0,
            audiencesRequest: { full: 0, light: 0 },
            users: 0,
        };
        stores.set(key, store);
    }

    store.users++;

    return store;
}

function release(key: string): void {
    const store = stores.get(key);

    if (store && --store.users <= 0) {
        stores.delete(key);
    }
}

/**
 * Forget every shared state (for a test that mounts screens without unmounting them).
 *
 * @internal
 */
export function resetKinetixNotificationRoutingState(): void {
    stores.clear();
}

/**
 * Notification routing for a team: which notification types go to which
 * audience on which channels, the named groups those rules can point at, and a
 * dry run of who a type would reach. Talks to the `notification-routing`
 * endpoints; every write needs `notification_routing.manage`.
 *
 * The rules, audiences and groups are shared by every screen using this
 * composable under the same route prefix (so the team): changing a group in
 * one updates the other. Loading flags, the preview and request guards belong
 * to each call.
 *
 * Writes throw the `kinetixFetch` error (a 422 carries `errors`) so the dialog
 * that asked can show it inline. Reads that can race (the page load, the
 * recipients preview, the member search) drop a response that lands after a
 * newer request.
 */
export function useKinetixNotificationRouting() {
    const page = usePage<KinetixSharedProps>();
    const currentPrefix = (): string => kinetixRoutePrefix(page);
    let prefix = currentPrefix();
    const base = (): string => `/${prefix}/notification-routing`;

    // The store of this prefix (the team): a switch of team moves to another.
    const store = shallowRef(acquire(prefix));
    const inScope = getCurrentScope() !== undefined;

    if (inScope) {
        onScopeDispose(() => release(prefix));
    }

    const types = computed({
        get: () => store.value.types.value,
        set: (value) => (store.value.types.value = value),
    });
    const channels = computed({
        get: () => store.value.channels.value,
        set: (value) => (store.value.channels.value = value),
    });
    const audiences = computed({
        get: () => store.value.audiences.value,
        set: (value) => (store.value.audiences.value = value),
    });
    const groups = computed({
        get: () => store.value.groups.value,
        set: (value) => (store.value.groups.value = value),
    });
    const shared = {
        get membersLoaded(): boolean {
            return store.value.membersLoaded;
        },
        set membersLoaded(value: boolean) {
            store.value.membersLoaded = value;
        },
    };

    const loading = ref(false);
    const loaded = ref(false);
    const loadFailed = ref(false);
    const groupsLoading = ref(false);
    const groupsLoaded = ref(false);
    const groupsLoadFailed = ref(false);

    /** The type whose recipients preview is showing, and its result. */
    const previewType = ref<string | null>(null);
    const preview = ref<KinetixNotificationRecipients | null>(null);
    const previewLoading = ref(false);
    const previewFailed = ref(false);

    // Bumped by every request of its kind: a response that lands after the
    // user asked again (or closed the preview) is dropped.
    let loadRequest = 0;
    let previewRequest = 0;
    let groupsRequest = 0;
    const memberSearch = createKinetixLatest();
    let newestMemberSearch: Promise<
        KinetixNotificationAudience[] | typeof KINETIX_SUPERSEDED
    > | null = null;

    async function load(): Promise<void> {
        const request = ++loadRequest;
        loading.value = true;
        loadFailed.value = false;

        try {
            const data = await kinetixFetch<{
                types: KinetixNotificationRoutingType[];
                channels: Array<{ key: string; label: string }>;
            }>(base());

            if (request !== loadRequest) {
                return;
            }

            types.value = data?.types ?? [];
            channels.value = data?.channels ?? [];
            loaded.value = true;
        } catch {
            if (request === loadRequest) {
                loadFailed.value = true;
            }
        } finally {
            if (request === loadRequest) {
                loading.value = false;
            }
        }
    }

    /**
     * Load the people a rule can point at. `members: false` skips the member
     * list (a screen whose member picker searches the server); a screen that
     * wants them again once they are loaded reuses them.
     */
    async function loadAudiences(
        options: { members?: boolean; force?: boolean } = {},
    ): Promise<void> {
        const withMembers = options.members ?? true;

        if (withMembers && shared.membersLoaded && !options.force) {
            return;
        }

        const state = store.value;
        const kind = withMembers ? 'full' : 'light';
        const request = ++state.audiencesRequest[kind];
        const groupsVersion = state.groupsVersion;
        const data = await kinetixFetch<KinetixNotificationAudiences>(
            `${base()}/audiences${withMembers ? '' : '?members=0'}`,
        );

        // Superseded by a newer request of its kind, or the team changed.
        if (state !== store.value || request !== state.audiencesRequest[kind]) {
            return;
        }

        const current = state.audiences.value;

        state.audiences.value = {
            members: withMembers ? (data?.members ?? []) : current.members,
            membersComplete: withMembers
                ? (data?.membersComplete ?? false)
                : current.membersComplete,
            roles: data?.roles ?? [],
            // A group created or deleted while this was in flight wins.
            groups:
                state.groupsVersion === groupsVersion
                    ? (data?.groups ?? [])
                    : current.groups,
        };
        state.membersLoaded = state.membersLoaded || withMembers;
    }

    /**
     * Search the team's members by name on the server (a team can be larger
     * than the 200 the audiences list carries). A newer search aborts the
     * request of an older one; the older call then resolves to the newest
     * search's result and its failure is swallowed, so a slow older response
     * never overwrites what the user last typed. Pass `ctx.signal` to abort
     * the request from the caller's side too.
     */
    async function searchMembers(
        query: string,
        ctx: { signal?: AbortSignal } = {},
    ): Promise<KinetixNotificationAudience[]> {
        const mine = memberSearch.run(async (signal) => {
            const data = await kinetixFetch<{
                members: KinetixNotificationAudience[];
            }>(`${base()}/audiences?search=${encodeURIComponent(query)}`, {
                signal: kinetixAnySignal(signal, ctx.signal),
            });

            return data?.members ?? [];
        });

        newestMemberSearch = mine;

        let current = mine;
        let result = await current;

        // Superseded: hand back what the newest search found (never its error).
        while (result === KINETIX_SUPERSEDED) {
            if (newestMemberSearch === null || newestMemberSearch === current) {
                return [];
            }

            current = newestMemberSearch;
            result = await current.catch(() => []);
        }

        return result;
    }

    async function loadGroups(): Promise<void> {
        const request = ++groupsRequest;
        groupsLoading.value = true;
        groupsLoadFailed.value = false;

        try {
            const data = await kinetixFetch<{
                groups: KinetixNotificationGroup[];
            }>(`${base()}/groups`);

            if (request === groupsRequest) {
                groups.value = data?.groups ?? [];
                groupsLoaded.value = true;
            }
        } catch {
            if (request === groupsRequest) {
                groupsLoadFailed.value = true;
            }
        } finally {
            if (request === groupsRequest) {
                groupsLoading.value = false;
            }
        }
    }

    /** Put a saved rule in its type: replace the one with its id, else add it. */
    function putRoute(route: KinetixNotificationRoute): void {
        types.value = types.value.map((type) => {
            if (type.key !== route.type) {
                return type;
            }

            const exists = type.routes.some((r) => r.id === route.id);

            return {
                ...type,
                routes: exists
                    ? type.routes.map((r) => (r.id === route.id ? route : r))
                    : [...type.routes, route],
            };
        });
    }

    /** Add a rule; an audience that already has one for the type has its channels replaced. */
    async function addRoute(
        input: KinetixNotificationRouteInput,
    ): Promise<KinetixNotificationRoute | null> {
        const data = await kinetixFetch<{ route: KinetixNotificationRoute }>(
            `${base()}/routes`,
            { method: 'POST', body: input },
        );

        if (data?.route) {
            putRoute(data.route);
        }

        return data?.route ?? null;
    }

    async function updateRoute(
        route: KinetixNotificationRoute,
        nextChannels: string[],
    ): Promise<KinetixNotificationRoute | null> {
        const data = await kinetixFetch<{ route: KinetixNotificationRoute }>(
            `${base()}/routes/${encodeURIComponent(String(route.id))}`,
            { method: 'PUT', body: { channels: nextChannels } },
        );

        if (data?.route) {
            putRoute(data.route);
        }

        return data?.route ?? null;
    }

    async function removeRoute(route: KinetixNotificationRoute): Promise<void> {
        await kinetixFetch(
            `${base()}/routes/${encodeURIComponent(String(route.id))}`,
            { method: 'DELETE' },
        );

        types.value = types.value.map((type) =>
            type.key === route.type
                ? {
                      ...type,
                      routes: type.routes.filter((r) => r.id !== route.id),
                  }
                : type,
        );
    }

    /** Dry run: who a type reaches, on which channels, and who is left out. */
    async function previewRecipients(type: string): Promise<void> {
        const request = ++previewRequest;
        previewType.value = type;
        preview.value = null;
        previewFailed.value = false;
        previewLoading.value = true;

        try {
            const data = await kinetixFetch<KinetixNotificationRecipients>(
                `${base()}/recipients?type=${encodeURIComponent(type)}`,
            );

            if (request === previewRequest) {
                preview.value = data ?? {
                    recipients: [],
                    skipped: [],
                    skippedCount: 0,
                    hasRules: false,
                    truncated: false,
                    fellBack: false,
                };
            }
        } catch {
            if (request === previewRequest) {
                previewFailed.value = true;
            }
        } finally {
            if (request === previewRequest) {
                previewLoading.value = false;
            }
        }
    }

    function closePreview(): void {
        previewRequest++;
        previewType.value = null;
        preview.value = null;
        previewLoading.value = false;
        previewFailed.value = false;
    }

    /**
     * Keep a saved group in the groups list, in the audiences a rule can pick
     * from, and (renamed) on the rules that point at it.
     */
    function putGroup(group: KinetixNotificationGroup): void {
        store.value.groupsVersion++;
        const exists = groups.value.some((g) => g.id === group.id);

        groups.value = exists
            ? groups.value.map((g) => (g.id === group.id ? group : g))
            : [...groups.value, group];

        const pick = { id: group.id, name: group.name };
        const listed = audiences.value.groups.some((g) => g.id === group.id);

        audiences.value = {
            ...audiences.value,
            groups: listed
                ? audiences.value.groups.map((g) =>
                      g.id === group.id ? pick : g,
                  )
                : [...audiences.value.groups, pick],
        };
        types.value = types.value.map((type) => ({
            ...type,
            routes: type.routes.map((r) =>
                r.audienceType === 'group' &&
                String(r.audienceId) === String(group.id)
                    ? { ...r, audienceLabel: group.name }
                    : r,
            ),
        }));
    }

    async function createGroup(
        input: KinetixNotificationGroupInput,
    ): Promise<KinetixNotificationGroup | null> {
        const data = await kinetixFetch<{ group: KinetixNotificationGroup }>(
            `${base()}/groups`,
            { method: 'POST', body: input },
        );

        if (data?.group) {
            putGroup(data.group);
        }

        return data?.group ?? null;
    }

    async function updateGroup(
        group: KinetixNotificationGroup,
        input: KinetixNotificationGroupInput,
    ): Promise<KinetixNotificationGroup | null> {
        const data = await kinetixFetch<{ group: KinetixNotificationGroup }>(
            `${base()}/groups/${encodeURIComponent(String(group.id))}`,
            { method: 'PUT', body: input },
        );

        if (data?.group) {
            putGroup(data.group);
        }

        return data?.group ?? null;
    }

    /** Add people to a group, keeping the ones already in it. */
    async function addMembers(
        group: KinetixNotificationGroup,
        memberIds: string[],
    ): Promise<KinetixNotificationGroup | null> {
        const data = await kinetixFetch<{ group: KinetixNotificationGroup }>(
            `${base()}/groups/${encodeURIComponent(String(group.id))}/members`,
            { method: 'POST', body: { member_ids: memberIds } },
        );

        if (data?.group) {
            putGroup(data.group);
        }

        return data?.group ?? null;
    }

    /** Take one person (by their member ref) out of a group. */
    async function removeMember(
        group: KinetixNotificationGroup,
        memberRef: string,
    ): Promise<KinetixNotificationGroup | null> {
        const data = await kinetixFetch<{ group: KinetixNotificationGroup }>(
            `${base()}/groups/${encodeURIComponent(String(group.id))}/members/${encodeURIComponent(memberRef)}`,
            { method: 'DELETE' },
        );

        if (data?.group) {
            putGroup(data.group);
        }

        return data?.group ?? null;
    }

    /** Delete a group; the rules that pointed at it go with it, on every screen. */
    async function deleteGroup(group: KinetixNotificationGroup): Promise<void> {
        await kinetixFetch(
            `${base()}/groups/${encodeURIComponent(String(group.id))}`,
            { method: 'DELETE' },
        );

        store.value.groupsVersion++;
        groups.value = groups.value.filter((g) => g.id !== group.id);
        audiences.value = {
            ...audiences.value,
            groups: audiences.value.groups.filter((g) => g.id !== group.id),
        };
        types.value = types.value.map((type) => ({
            ...type,
            routes: type.routes.filter(
                (r) =>
                    !(
                        r.audienceType === 'group' &&
                        String(r.audienceId) === String(group.id)
                    ),
            ),
        }));
    }

    if (inScope) {
        // A team switch: leave the old team's store for the new one and drop
        // what this call had in flight or on screen.
        watch(currentPrefix, (next) => {
            if (next === prefix) {
                return;
            }

            release(prefix);
            prefix = next;
            store.value = acquire(next);
            loadRequest++;
            groupsRequest++;
            memberSearch.cancel();
            closePreview();
            loaded.value = false;
            loadFailed.value = false;
            loading.value = false;
            groupsLoaded.value = false;
            groupsLoadFailed.value = false;
            groupsLoading.value = false;
        });
    }

    return {
        types,
        channels,
        audiences,
        groups,
        loading,
        loaded,
        loadFailed,
        groupsLoading,
        groupsLoaded,
        groupsLoadFailed,
        previewType,
        preview,
        previewLoading,
        previewFailed,
        load,
        loadAudiences,
        searchMembers,
        loadGroups,
        addRoute,
        updateRoute,
        removeRoute,
        previewRecipients,
        closePreview,
        createGroup,
        updateGroup,
        addMembers,
        removeMember,
        deleteGroup,
    };
}

/**
 * The first message of each field in a 422. A nested key (`channels.0`,
 * `member_ids.3`) folds into its field. Anything that is not a validation
 * error shows under `form`: the server's own message when it sent one, else
 * `fallback` (never a raw "HTTP error! status: 404").
 */
export function notificationRoutingErrors(
    error: unknown,
    fallback: string,
): Record<string, string> {
    const bag = (error as { errors?: Record<string, string[]> }).errors;

    if (bag && typeof bag === 'object') {
        const folded: Record<string, string> = {};

        for (const [key, value] of Object.entries(bag)) {
            const field = key.replace(/\.\d+(\..*)?$/, '');

            folded[field] ??= Array.isArray(value) ? value[0] : String(value);
        }

        return folded;
    }

    return { form: notificationRoutingMessage(error, fallback) };
}

/**
 * What to tell the user about a failed request: the server's own message only
 * where it is written for people (a validation message, a refusal, a throttle);
 * a missing record, a conflict, a server fault or a dropped connection shows
 * `fallback`, never "No query results for model [...]" or "HTTP error!".
 */
export function notificationRoutingMessage(
    error: unknown,
    fallback: string,
): string {
    const status = (error as { status?: number }).status;
    const message = error instanceof Error ? error.message : '';
    const raw =
        status === undefined ||
        status === 404 ||
        status === 409 ||
        status >= 500 ||
        message.startsWith('HTTP error!');

    return message && !raw ? message : fallback;
}
