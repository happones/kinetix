<script setup lang="ts">
import { usePage, router, usePoll } from '@inertiajs/vue3';
import { useEchoNotification } from '@laravel/echo-vue';
import { storeToRefs } from 'pinia';
import { computed, watch, onMounted, onUnmounted } from 'vue';
import { useNotificationsStore } from '@/stores/kinetixNotifications';
import type {
    KinetixAction,
    KinetixNotification,
    KinetixSharedProps,
} from '@/types/kinetix';
import KinetixNotificationDrawer from './KinetixNotificationDrawer.vue';
import KinetixNotificationTrigger from './KinetixNotificationTrigger.vue';

// Allow consumers to override the Echo channel model prefix.
// Defaults to `App.Models.User` which matches Laravel's standard User model.
// Change this if your app uses a different User model namespace.
const props = withDefaults(
    defineProps<{
        channelModel?: string;
    }>(),
    {
        channelModel: 'App.Models.User',
    },
);

const page = usePage<KinetixSharedProps>();
const store = useNotificationsStore();
const {
    notifications,
    isOpen,
    seenNotificationIds,
    isInitialized,
    isDatabaseMode,
    userId,
} = storeToRefs(store);

const { closeDrawer, playNotificationSound, triggerToast, syncFromProps } =
    store;

// Build the private channel name: `{channelModel}.{userId}`
// e.g. "App.Models.User.1" → Echo subscribes to "private-App.Models.User.1"
const echoChannel = computed(() =>
    userId.value !== null ? `${props.channelModel}.${userId.value}` : null,
);

// Handler for incoming broadcast notifications
const onBroadcastNotification = (notification: Record<string, unknown>) => {
    const notif: KinetixNotification = {
        id: (notification.id as string) ?? crypto.randomUUID(),
        title: (notification.title as string) ?? '',
        description: notification.description as string | undefined,
        status:
            (notification.status as KinetixNotification['status']) ?? 'info',
        duration: notification.duration as number | undefined,
        created_at:
            (notification.created_at as string) ?? new Date().toISOString(),
        actions: (notification.actions as KinetixAction[]) ?? [],
        type: notification.type as string | undefined,
        team: notification.team as string | number | null | undefined,
    };

    // Team-scoped bell: the broadcast channel is per-user, so a notification
    // stamped for another team still arrives here — don't toast it in the
    // team the user is NOT looking at (the server-filtered list ignores it).
    const activeTeamId = page.props.kinetix_config?.team_id;
    const isOtherTeam =
        notif.team != null &&
        activeTeamId != null &&
        String(notif.team) !== String(activeTeamId);

    if (!isOtherTeam) {
        triggerToast(notif);
        playNotificationSound();
    }

    if (isDatabaseMode.value) {
        // Pre-register the id so the props sync below (and any poll that races
        // it) doesn't toast the same notification a second time.
        seenNotificationIds.value.add(notif.id);

        router.reload({
            only: ['kinetix_notifications'],
            onSuccess: () => {
                if (page.props.kinetix_notifications) {
                    syncFromProps(page.props.kinetix_notifications);
                }
            },
        });

        return;
    }

    if (!isOtherTeam) {
        syncFromProps([notif]);
    }
};

// --- Real-time Echo listener ---
const { listen: echoListen, stopListening: echoStop } = useEchoNotification<
    Record<string, unknown>
>(
    echoChannel.value ?? `${props.channelModel}.0`,
    onBroadcastNotification,
    undefined,
    [echoChannel],
);

// Only actively listen when a user is authenticated
watch(
    echoChannel,
    (channel) => {
        if (channel) {
            echoListen();

            return;
        }

        echoStop();
    },
    { immediate: true },
);

// --- Database-mode fallback polling (Inertia usePoll) -----------------------
// Without Echo, this is what makes new database notifications appear (badge +
// toast via the store's props sync) instead of waiting for the next visit.
// Interval comes from `kinetix.notifications.poll` (ms, 0 = off); usePoll
// pauses in background tabs and cleans up on unmount.
const pollInterval = page.props.kinetix_config?.poll ?? 0;
const poll = usePoll(
    pollInterval || 60000,
    { only: ['kinetix_notifications'] },
    { autoStart: false },
);

const shouldPoll = computed(
    () =>
        isDatabaseMode.value && pollInterval > 0 && echoChannel.value !== null,
);

watch(
    shouldPoll,
    (active) => {
        if (active) {
            poll.start();

            return;
        }

        poll.stop();
    },
    { immediate: true },
);

// Watch Inertia shared props for kinetix_notifications. Inertia replaces props
// wholesale on every visit/reload, so watching the array reference is enough.
watch(
    () => page.props.kinetix_notifications,
    (newVal) => {
        syncFromProps(newVal);
    },
    { immediate: true },
);

// Escape key listener
const handleKeyDown = (e: KeyboardEvent) => {
    if (e.key === 'Escape' && isOpen.value) {
        closeDrawer();
    }
};

// On mount, restore/listen based on driver
onMounted(() => {
    window.addEventListener('keydown', handleKeyDown);

    if (!isDatabaseMode.value) {
        try {
            const savedNotifs = localStorage.getItem(
                'kinetix_notifications_list',
            );
            const savedSeen = localStorage.getItem('kinetix_seen_ids');

            if (savedNotifs) {
                notifications.value = JSON.parse(savedNotifs);
            }

            if (savedSeen) {
                const arr = JSON.parse(savedSeen);
                seenNotificationIds.value = new Set(arr);
            }
        } catch (e) {
            console.error(
                'Failed to restore Kinetix notifications from storage',
                e,
            );
        }
    }

    if (isDatabaseMode.value && page.props.kinetix_notifications) {
        syncFromProps(page.props.kinetix_notifications);
    }

    // Set initialized to true after small delay to avoid playing audio for initial list load
    setTimeout(() => {
        isInitialized.value = true;
    }, 500);
});

onUnmounted(() => {
    window.removeEventListener('keydown', handleKeyDown);
    // Stop the Echo subscription so it doesn't leak past the component's life.
    echoStop();
    // Ensure scroll is unlocked when component unmounts
    document.body.style.overflow = '';
});
</script>

<template>
    <div class="kinetix-notifications-container relative flex items-center">
        <KinetixNotificationTrigger />
        <KinetixNotificationDrawer />
    </div>
</template>

<style scoped>
/* Scrollbar styling */
::-webkit-scrollbar {
    width: 4px;
}
::-webkit-scrollbar-track {
    background: transparent;
}
::-webkit-scrollbar-thumb {
    background: #e5e5e5;
    border-radius: 2px;
}
.dark ::-webkit-scrollbar-thumb {
    background: #262626;
}
</style>
