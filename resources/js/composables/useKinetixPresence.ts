import { usePage } from '@inertiajs/vue3';
import { computed, getCurrentScope, onScopeDispose, ref } from 'vue';
import { joinKinetixPresence } from '@/composables/kinetixEcho';
import type {
    KinetixPresenceState,
    KinetixPresenceUser,
    KinetixSharedProps,
} from '@/types/kinetix';

/**
 * Live presence: join the (team-resolved) presence channel and track who's
 * online. Powers the <KinetixOnlineUsers> facepile and online dots elsewhere.
 * Requires broadcasting configured (`configureEcho`); no-ops without a channel
 * or without a configured Echo. `@laravel/echo-vue` is imported lazily, only
 * once there is a channel to join, so the members fill in a tick later.
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

    if (channel && typeof window !== 'undefined') {
        let release: (() => void) | null = null;
        let disposed = false;

        // Registered synchronously, so an unmount before the join resolves
        // still releases it.
        if (getCurrentScope()) {
            onScopeDispose(() => {
                disposed = true;
                release?.();
                release = null;
            });
        }

        void joinKinetixPresence(channel).then((joined) => {
            if (joined === null) {
                return;
            }

            if (disposed) {
                joined.release();

                return;
            }

            release = joined.release;

            joined.channel
                .here((list: KinetixPresenceUser[]) => {
                    if (disposed) {
                        return;
                    }

                    const next = new Map<string, KinetixPresenceUser>();
                    list.forEach((user) => next.set(String(user.id), user));
                    commit(next);
                })
                .joining((user: KinetixPresenceUser) => {
                    if (disposed) {
                        return;
                    }

                    const next = new Map(members.value);
                    next.set(String(user.id), user);
                    commit(next);
                })
                .leaving((user: KinetixPresenceUser) => {
                    if (disposed) {
                        return;
                    }

                    const next = new Map(members.value);
                    next.delete(String(user.id));
                    commit(next);
                });
        });
    }

    return { users, count, isOnline, channel };
}
