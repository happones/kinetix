import { usePage } from '@inertiajs/vue3';
import { echo, echoIsConfigured } from '@laravel/echo-vue';
import { onUnmounted, ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type {
    KinetixChannel,
    KinetixChannelLink,
    KinetixChannelProvider,
    KinetixSharedProps,
} from '@/types/kinetix';

/** What a line broadcasts when its status or QR changes. */
export interface KinetixChannelUpdate {
    id: number | string;
    status: KinetixChannel['status'];
    handle: string | null;
    qr: string | null;
}

interface ChannelResponse {
    channel: KinetixChannel;
}

interface LinkResponse extends ChannelResponse {
    link: KinetixChannelLink | null;
}

/**
 * The team's lines, talking to Kinetix's `channels` endpoints: list, add
 * (provisioning the line with its provider), link by QR / pairing code,
 * poll status, unlink, restart, test, rename and delete. With
 * `kinetix.channels.broadcast` on and Echo configured, `listen()` receives
 * status and QR changes as they happen.
 */
export function useKinetixChannels() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/channels`;

    const channels = ref<KinetixChannel[]>([]);
    const providers = ref<KinetixChannelProvider[]>([]);
    const limit = ref<number | null>(null);
    const broadcast = ref<string | null>(null);
    /** Whether lines have agents (the inbox is on). */
    const agentsEnabled = ref(false);
    const loading = ref(false);
    const loaded = ref(false);

    /** Replace a line in the list with its latest copy. */
    function put(channel: KinetixChannel): void {
        const index = channels.value.findIndex((c) => c.id === channel.id);

        if (index === -1) {
            channels.value = [...channels.value, channel];
        } else {
            channels.value = channels.value.map((c, i) =>
                i === index ? channel : c,
            );
        }
    }

    async function load(): Promise<void> {
        loading.value = true;

        try {
            const data = await kinetixFetch<{
                channels: KinetixChannel[];
                providers: KinetixChannelProvider[];
                limit: number | null;
                broadcast: string | null;
                agents?: boolean;
            }>(base());
            channels.value = data?.channels ?? [];
            providers.value = data?.providers ?? [];
            limit.value = data?.limit ?? null;
            broadcast.value = data?.broadcast ?? null;
            agentsEnabled.value = data?.agents ?? false;
        } finally {
            loading.value = false;
            loaded.value = true;
        }
    }

    async function create(
        payload: Record<string, unknown>,
    ): Promise<LinkResponse | null> {
        const data = await kinetixFetch<LinkResponse>(base(), {
            method: 'POST',
            body: payload,
        });

        if (data?.channel) {
            put(data.channel);
        }

        return data;
    }

    async function rename(
        channel: KinetixChannel,
        name: string,
    ): Promise<void> {
        const data = await kinetixFetch<ChannelResponse>(
            `${base()}/${channel.id}`,
            { method: 'PUT', body: { name } },
        );

        if (data?.channel) {
            put(data.channel);
        }
    }

    async function remove(channel: KinetixChannel): Promise<void> {
        await kinetixFetch(`${base()}/${channel.id}`, { method: 'DELETE' });
        channels.value = channels.value.filter((c) => c.id !== channel.id);
    }

    /** A fresh QR — or, with a phone number, a pairing code. */
    async function link(
        channel: KinetixChannel,
        phone?: string | null,
    ): Promise<KinetixChannelLink | null> {
        const query = phone ? `?phone=${encodeURIComponent(phone)}` : '';
        const data = await kinetixFetch<LinkResponse>(
            `${base()}/${channel.id}/link${query}`,
        );

        if (data?.channel) {
            put(data.channel);
        }

        return data?.link ?? null;
    }

    async function status(
        channel: KinetixChannel,
    ): Promise<KinetixChannel | null> {
        const data = await kinetixFetch<ChannelResponse>(
            `${base()}/${channel.id}/status`,
        );

        if (data?.channel) {
            put(data.channel);
        }

        return data?.channel ?? null;
    }

    async function action(
        channel: KinetixChannel,
        name: 'disconnect' | 'restart',
    ): Promise<void> {
        const data = await kinetixFetch<ChannelResponse>(
            `${base()}/${channel.id}/${name}`,
            { method: 'POST' },
        );

        if (data?.channel) {
            put(data.channel);
        }
    }

    const disconnect = (channel: KinetixChannel): Promise<void> =>
        action(channel, 'disconnect');
    const restart = (channel: KinetixChannel): Promise<void> =>
        action(channel, 'restart');

    async function test(
        channel: KinetixChannel,
        to: string,
        text: string,
    ): Promise<void> {
        await kinetixFetch(`${base()}/${channel.id}/test`, {
            method: 'POST',
            body: { to, text },
        });
    }

    /** Who answers a line, and the team members to choose from. */
    async function agents(channel: KinetixChannel): Promise<{
        agents: (number | string)[];
        members: {
            id: number | string;
            name: string | null;
            seesAll: boolean;
        }[];
    } | null> {
        return kinetixFetch(`${base()}/${channel.id}/agents`);
    }

    async function saveAgents(
        channel: KinetixChannel,
        userIds: (number | string)[],
    ): Promise<void> {
        await kinetixFetch(`${base()}/${channel.id}/agents`, {
            method: 'PUT',
            body: { user_ids: userIds },
        });
    }

    let listening: string | null = null;

    /**
     * Receive status and QR changes as they happen, when broadcasting is on
     * and Echo is configured; a no-op otherwise (the linker polls anyway).
     */
    function listen(onUpdate: (update: KinetixChannelUpdate) => void): void {
        if (!broadcast.value || listening || !echoIsConfigured()) {
            return;
        }

        listening = broadcast.value;
        echo()
            .private(listening)
            .listen('.channel.updated', (update: KinetixChannelUpdate) => {
                const current = channels.value.find((c) => c.id === update.id);

                if (current) {
                    put({
                        ...current,
                        status: update.status,
                        handle: update.handle ?? current.handle,
                    });
                }

                onUpdate(update);
            });
    }

    onUnmounted(() => {
        if (listening) {
            echo().leave(listening);
            listening = null;
        }
    });

    return {
        channels,
        providers,
        limit,
        broadcast,
        agentsEnabled,
        loading,
        loaded,
        load,
        create,
        rename,
        remove,
        link,
        status,
        disconnect,
        restart,
        test,
        agents,
        saveAgents,
        listen,
    };
}
