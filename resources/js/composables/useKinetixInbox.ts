import { usePage } from '@inertiajs/vue3';
import { echo, echoIsConfigured } from '@laravel/echo-vue';
import { computed, onUnmounted, ref } from 'vue';
import {
    isKinetixAbort,
    kinetixFetch,
    kinetixRoutePrefix,
} from '@/composables/useKinetixHttp';
import type {
    KinetixConversation,
    KinetixConversationMessage,
    KinetixInboxChannel,
    KinetixInboxFilter,
    KinetixSharedProps,
} from '@/types/kinetix';

interface IndexResponse {
    conversations: KinetixConversation[];
    hasMore: boolean;
    channels: KinetixInboxChannel[];
    me: { id: number | string; seesAll: boolean };
    poll: { list: number; thread: number };
    composer: { maxUploadKb: number; accept: string };
}

export interface KinetixInboxDraft {
    body: string;
    file?: File | null;
    replyTo?: KinetixConversationMessage | null;
    note?: boolean;
}

/**
 * The team inbox over Kinetix's `inbox` endpoints: the conversation list
 * (search, filters, infinite scroll), the open thread (older pages, catching
 * up), optimistic sending with files, replies and notes, per-user reads,
 * assignment and archiving. Updates arrive by broadcast when the lines
 * broadcast and Echo is configured, else by polling.
 */
export function useKinetixInbox() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/inbox`;

    const conversations = ref<KinetixConversation[]>([]);
    const channels = ref<KinetixInboxChannel[]>([]);
    const me = ref<IndexResponse['me'] | null>(null);
    const composer = ref<IndexResponse['composer']>({
        maxUploadKb: 16384,
        accept: '',
    });
    const hasMore = ref(false);
    const loading = ref(false);
    const loaded = ref(false);
    const pageNumber = ref(1);

    const search = ref('');
    const filter = ref<KinetixInboxFilter>('all');
    const channelId = ref<number | string | null>(null);

    const active = ref<KinetixConversation | null>(null);
    const messages = ref<KinetixConversationMessage[]>([]);
    const olderAvailable = ref(false);
    const loadingMessages = ref(false);
    const loadingOlder = ref(false);

    let poll = { list: 15, thread: 5 };
    let listAbort: AbortController | null = null;
    let threadAbort: AbortController | null = null;
    let listTimer: ReturnType<typeof setInterval> | null = null;
    let threadTimer: ReturnType<typeof setInterval> | null = null;
    let localCounter = 0;
    const subscribed: string[] = [];

    const broadcasting = computed(() =>
        channels.value.some((line) => !!line.broadcast),
    );

    function query(): string {
        const params = new URLSearchParams({
            filter: filter.value,
            page: String(pageNumber.value),
        });

        if (search.value.trim()) {
            params.set('search', search.value.trim());
        }

        if (channelId.value !== null) {
            params.set('channel', String(channelId.value));
        }

        return params.toString();
    }

    function putConversation(conversation: KinetixConversation): void {
        const index = conversations.value.findIndex(
            (c) => c.id === conversation.id,
        );
        const next =
            index === -1
                ? [conversation, ...conversations.value]
                : conversations.value.map((c, i) =>
                      i === index ? conversation : c,
                  );

        // Newest activity first, as the server orders them.
        conversations.value = next.sort((a, b) =>
            (b.lastMessageAt ?? '').localeCompare(a.lastMessageAt ?? ''),
        );

        if (active.value?.id === conversation.id) {
            active.value = conversation;
        }
    }

    /** Load the list from its first page (a new search or filter), or the next page. */
    async function loadList(more = false): Promise<void> {
        listAbort?.abort();
        listAbort = new AbortController();
        loading.value = true;
        pageNumber.value = more ? pageNumber.value + 1 : 1;

        try {
            const data = await kinetixFetch<IndexResponse>(
                `${base()}?${query()}`,
                { signal: listAbort.signal },
            );

            if (!data) {
                return;
            }

            conversations.value = more
                ? [
                      ...conversations.value,
                      ...data.conversations.filter(
                          (c) =>
                              !conversations.value.some((o) => o.id === c.id),
                      ),
                  ]
                : data.conversations;
            hasMore.value = data.hasMore;
            channels.value = data.channels;
            me.value = data.me;
            composer.value = data.composer;
            poll = data.poll;
            subscribe();
        } catch (error) {
            if (!isKinetixAbort(error)) {
                throw error;
            }
        } finally {
            loading.value = false;
            loaded.value = true;
        }
    }

    /** Merge the first page into what's shown (a poll), keeping what's loaded below. */
    async function refreshList(): Promise<void> {
        if (document.hidden) {
            return;
        }

        const params = new URLSearchParams(query());
        params.set('page', '1');

        const data = await kinetixFetch<IndexResponse>(
            `${base()}?${params.toString()}`,
        ).catch(() => null);

        data?.conversations.forEach(putConversation);
    }

    async function refreshConversation(
        id: number | string,
    ): Promise<KinetixConversation | null> {
        const data = await kinetixFetch<{
            conversation: KinetixConversation;
        }>(`${base()}/${id}`).catch(() => null);

        if (data?.conversation) {
            putConversation(data.conversation);
        }

        return data?.conversation ?? null;
    }

    function putMessage(message: KinetixConversationMessage): void {
        if (message.conversationId !== active.value?.id) {
            return;
        }

        const index = messages.value.findIndex(
            (m) =>
                m.id === message.id ||
                (!!message.localId && m.localId === message.localId),
        );

        messages.value =
            index === -1
                ? [...messages.value, message].sort(
                      (a, b) => Number(a.id) - Number(b.id),
                  )
                : messages.value.map((m, i) => (i === index ? message : m));
    }

    async function open(conversation: KinetixConversation): Promise<void> {
        active.value = conversation;
        messages.value = [];
        olderAvailable.value = false;
        threadAbort?.abort();
        threadAbort = new AbortController();
        loadingMessages.value = true;

        try {
            const data = await kinetixFetch<{
                messages: KinetixConversationMessage[];
                hasMore: boolean;
            }>(`${base()}/${conversation.id}/messages`, {
                signal: threadAbort.signal,
            });

            messages.value = data?.messages ?? [];
            olderAvailable.value = data?.hasMore ?? false;
            await markRead();
        } catch (error) {
            if (!isKinetixAbort(error)) {
                throw error;
            }
        } finally {
            loadingMessages.value = false;
        }

        startThreadPolling();
    }

    function close(): void {
        threadAbort?.abort();
        active.value = null;
        messages.value = [];
        stopThreadPolling();
    }

    async function loadOlder(): Promise<void> {
        const first = messages.value.find((m) => !m.localId);

        if (!active.value || !first || loadingOlder.value) {
            return;
        }

        loadingOlder.value = true;

        try {
            const data = await kinetixFetch<{
                messages: KinetixConversationMessage[];
                hasMore: boolean;
            }>(`${base()}/${active.value.id}/messages?before=${first.id}`);

            messages.value = [...(data?.messages ?? []), ...messages.value];
            olderAvailable.value = data?.hasMore ?? false;
        } finally {
            loadingOlder.value = false;
        }
    }

    /** Fetch the latest page again: new messages and fresh receipts. */
    async function catchUp(): Promise<void> {
        if (!active.value || document.hidden) {
            return;
        }

        const id = active.value.id;
        const data = await kinetixFetch<{
            messages: KinetixConversationMessage[];
        }>(`${base()}/${id}/messages`).catch(() => null);
        const before = messages.value.length;

        data?.messages.forEach(putMessage);

        if (messages.value.length !== before) {
            await markRead();
        }
    }

    async function markRead(): Promise<void> {
        const conversation = active.value;
        const last = [...messages.value].reverse().find((m) => !m.localId);

        if (!conversation || !last) {
            return;
        }

        await kinetixFetch(`${base()}/${conversation.id}/read`, {
            method: 'POST',
            body: { message_id: last.id },
        }).catch(() => null);

        putConversation({ ...conversation, unread: 0 });
    }

    /**
     * Send from the open conversation. The message shows at once, on its way;
     * the server's copy (sent, or failed with its reason) replaces it.
     */
    async function send(draft: KinetixInboxDraft): Promise<void> {
        const conversation = active.value;

        if (!conversation) {
            return;
        }

        localCounter += 1;
        const localId = `local-${localCounter}`;
        const optimistic: KinetixConversationMessage = {
            id: localId,
            localId,
            conversationId: conversation.id,
            direction: draft.note ? 'note' : 'out',
            type: draft.file ? fileType(draft.file.type) : 'text',
            body: draft.body.trim() || null,
            status: 'pending',
            error: null,
            hasMedia: !!draft.file,
            mediaReady: false,
            mediaMime: draft.file?.type ?? null,
            mediaName: draft.file?.name ?? null,
            mediaSize: draft.file?.size ?? null,
            replyTo: draft.replyTo
                ? {
                      id: draft.replyTo.id,
                      body: draft.replyTo.body,
                      type: draft.replyTo.type,
                      direction: draft.replyTo.direction,
                  }
                : null,
            authorName: null,
            sentAt: new Date().toISOString(),
        };

        messages.value = [...messages.value, optimistic];

        // Multipart only to carry a file; JSON otherwise.
        let body: FormData | Record<string, unknown>;

        if (draft.file) {
            body = new FormData();
            body.append('attachment', draft.file);

            if (draft.body.trim()) {
                body.append('body', draft.body);
            }

            if (draft.replyTo) {
                body.append('reply_to', String(draft.replyTo.id));
            }
        } else {
            body = {
                body: draft.body,
                reply_to: draft.replyTo?.id ?? null,
                note: !!draft.note,
            };
        }

        try {
            const data = await kinetixFetch<{
                message: KinetixConversationMessage;
            }>(`${base()}/${conversation.id}/messages`, {
                method: 'POST',
                body,
            });

            if (data?.message) {
                putMessage({ ...data.message, localId });
                putConversation({
                    ...conversation,
                    lastMessage: data.message,
                    lastMessageAt: data.message.sentAt,
                });
            }
        } catch (error) {
            putMessage({
                ...optimistic,
                status: 'failed',
                error: error instanceof Error ? error.message : String(error),
            });
        }
    }

    async function retry(message: KinetixConversationMessage): Promise<void> {
        putMessage({ ...message, status: 'pending', error: null });

        const data = await kinetixFetch<{
            message: KinetixConversationMessage;
        }>(`${base()}/messages/${message.id}/retry`, { method: 'POST' }).catch(
            (error: unknown) => {
                putMessage({
                    ...message,
                    status: 'failed',
                    error: error instanceof Error ? error.message : null,
                });

                return null;
            },
        );

        if (data?.message) {
            putMessage(data.message);
        }
    }

    async function assignees(
        conversation: KinetixConversation,
    ): Promise<{ id: number | string; name: string | null }[]> {
        const data = await kinetixFetch<{
            assignees: { id: number | string; name: string | null }[];
        }>(`${base()}/${conversation.id}/assignees`);

        return data?.assignees ?? [];
    }

    async function conversationAction(
        conversation: KinetixConversation,
        action: 'assign' | 'archive' | 'unarchive',
        body?: unknown,
    ): Promise<void> {
        const data = await kinetixFetch<{
            conversation: KinetixConversation;
        }>(`${base()}/${conversation.id}/${action}`, {
            method: 'POST',
            body,
        });

        if (data?.conversation) {
            putConversation(data.conversation);
        }
    }

    const assign = (
        conversation: KinetixConversation,
        userId: number | string | null,
    ): Promise<void> =>
        conversationAction(conversation, 'assign', { user_id: userId });
    const archive = (conversation: KinetixConversation): Promise<void> =>
        conversationAction(conversation, 'archive');
    const unarchive = (conversation: KinetixConversation): Promise<void> =>
        conversationAction(conversation, 'unarchive');

    /** Where a message's file is served. */
    const mediaUrl = (
        message: KinetixConversationMessage,
        download = false,
    ): string =>
        `${base()}/messages/${message.id}/media${download ? '?download=1' : ''}`;

    // ── Live updates ──────────────────────────────────────────────────────

    async function onUpdate(update: {
        conversationId: number | string;
        messageId: number | string | null;
    }): Promise<void> {
        await refreshConversation(update.conversationId);

        if (
            update.messageId !== null &&
            update.conversationId === active.value?.id
        ) {
            const data = await kinetixFetch<{
                message: KinetixConversationMessage;
            }>(`${base()}/messages/${update.messageId}`).catch(() => null);

            if (data?.message) {
                putMessage(data.message);

                if (data.message.direction === 'in') {
                    await markRead();
                }
            }
        }
    }

    function subscribe(): void {
        if (!echoIsConfigured()) {
            startListPolling();

            return;
        }

        channels.value.forEach((line) => {
            if (line.broadcast && !subscribed.includes(line.broadcast)) {
                subscribed.push(line.broadcast);
                echo()
                    .private(line.broadcast)
                    .listen('.inbox.updated', onUpdate);
            }
        });

        if (!broadcasting.value) {
            startListPolling();
        }
    }

    function startListPolling(): void {
        if (!listTimer && poll.list > 0) {
            listTimer = setInterval(() => void refreshList(), poll.list * 1000);
        }
    }

    function startThreadPolling(): void {
        stopThreadPolling();

        if ((!broadcasting.value || !echoIsConfigured()) && poll.thread > 0) {
            threadTimer = setInterval(() => void catchUp(), poll.thread * 1000);
        }
    }

    function stopThreadPolling(): void {
        if (threadTimer) {
            clearInterval(threadTimer);
            threadTimer = null;
        }
    }

    onUnmounted(() => {
        listAbort?.abort();
        threadAbort?.abort();

        if (listTimer) {
            clearInterval(listTimer);
        }

        stopThreadPolling();
        subscribed.forEach((name) => echo().leave(name));
    });

    return {
        conversations,
        channels,
        me,
        composer,
        hasMore,
        loading,
        loaded,
        search,
        filter,
        channelId,
        active,
        messages,
        olderAvailable,
        loadingMessages,
        loadingOlder,
        loadList,
        refreshConversation,
        open,
        close,
        loadOlder,
        catchUp,
        send,
        retry,
        assignees,
        assign,
        archive,
        unarchive,
        mediaUrl,
    };
}

/** The message type a file goes out as. */
export function fileType(mime: string): string {
    if (mime.startsWith('image/')) {
        return 'image';
    }

    if (mime.startsWith('video/')) {
        return 'video';
    }

    if (mime.startsWith('audio/')) {
        return 'audio';
    }

    return 'document';
}
