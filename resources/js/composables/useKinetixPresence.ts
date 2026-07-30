import { usePage } from '@inertiajs/vue3';
import { useEchoPresence } from '@laravel/echo-vue';
import { computed, onUnmounted, ref } from 'vue';
import type {
    KinetixPresenceState,
    KinetixPresenceUser,
    KinetixSharedProps,
} from '@/types/kinetix';

/**
 * Live presence: join the (team-resolved) presence channel and track who's
 * online. Powers the <KinetixOnlineUsers> facepile and online dots elsewhere.
 * Requires broadcasting configured (`configureEcho`); no-ops without a channel.
 */
export function useKinetixPresence(channelOverride?: string) {
    const page = usePage<KinetixSharedProps>();
    const state = computed<KinetixPresenceState | undefined>(
        () => page.props.kinetix_presence,
    );
    const channel = channelOverride ?? state.value?.channel ?? null;

    const members = ref<Map<string, KinetixPresenceUser>>(new Map());
    const users = computed<KinetixPresenceUser[]>(() =>
        Array.from(members.value.values()),
    );
    const count = computed(() => members.value.size);

    const isOnline = (id: number | string): boolean =>
        members.value.has(String(id));

    /** Reassign the Map so computed refs re-evaluate. */
    const commit = (next: Map<string, KinetixPresenceUser>): void => {
        members.value = next;
    };

    if (channel) {
        const { channel: getChannel, leave } =
            useEchoPresence<KinetixPresenceUser>(channel);

        getChannel()
            .here((list: KinetixPresenceUser[]) => {
                const next = new Map<string, KinetixPresenceUser>();
                list.forEach((user) => next.set(String(user.id), user));
                commit(next);
            })
            .joining((user: KinetixPresenceUser) => {
                const next = new Map(members.value);
                next.set(String(user.id), user);
                commit(next);
            })
            .leaving((user: KinetixPresenceUser) => {
                const next = new Map(members.value);
                next.delete(String(user.id));
                commit(next);
            });

        onUnmounted(() => leave());
    }

    return { users, count, isOnline, channel };
}
