import { router, usePage } from '@inertiajs/vue3';
import { defineStore } from 'pinia';
import { ref, computed } from 'vue';
import { toast } from 'vue-sonner';
import { kinetixFetch } from '@/composables/useKinetixHttp';
import type {
    KinetixAction,
    KinetixNotification,
    KinetixSharedProps,
} from '@/types/kinetix';

export const useNotificationsStore = defineStore('kinetixNotifications', () => {
    const notifications = ref<KinetixNotification[]>([]);
    const isOpen = ref(false);
    const seenNotificationIds = ref<Set<string>>(new Set());
    const isInitialized = ref(false);

    const page = usePage<KinetixSharedProps>();

    const isDatabaseMode = computed(() => {
        return !!page.props.kinetix_config?.database;
    });

    const routePrefix = computed(() => {
        return page.props.kinetix_config?.route_prefix ?? '_kinetix';
    });

    const userId = computed(() => {
        return page.props.auth?.user?.id ?? null;
    });

    const unreadCount = computed(() => {
        return notifications.value.filter((n) => !n.read).length;
    });

    const toggleDrawer = () => {
        isOpen.value = !isOpen.value;
    };

    const closeDrawer = () => {
        isOpen.value = false;
    };

    const saveToStorage = () => {
        if (isDatabaseMode.value) {
            return;
        }

        localStorage.setItem(
            'kinetix_notifications_list',
            JSON.stringify(notifications.value),
        );
        localStorage.setItem(
            'kinetix_seen_ids',
            JSON.stringify(Array.from(seenNotificationIds.value)),
        );
    };

    const playNotificationSound = () => {
        const soundConfig = page.props.kinetix_config?.sound;

        if (!soundConfig?.enabled || !soundConfig?.path) {
            return;
        }

        try {
            const audio = new Audio(soundConfig.path);
            audio.play();
        } catch (e) {
            console.warn('Failed to play Kinetix notification audio:', e);
        }
    };

    const sendRequest = async (url: string, method: string): Promise<void> => {
        try {
            // kinetixFetch sends the XSRF token + Accept/X-Requested-With (so Laravel
            // returns JSON errors instead of a 302 fetch would silently follow) and
            // throws on a non-2xx response.
            await kinetixFetch(url, { method });

            router.reload({
                only: ['kinetix_notifications'],
                onSuccess: () => {
                    if (page.props.kinetix_notifications) {
                        syncFromProps(page.props.kinetix_notifications);
                    }
                },
            });
        } catch (e) {
            console.error(
                `Kinetix notifications request failed: ${method} ${url}`,
                e,
            );

            // The optimistic mutation already removed/changed the item locally; the
            // server rejected it, so re-sync from the server to restore the truth
            // (the item reappears, signalling the action did not take effect).
            router.reload({
                only: ['kinetix_notifications'],
                onSuccess: () => {
                    if (page.props.kinetix_notifications) {
                        syncFromProps(page.props.kinetix_notifications);
                    }
                },
            });
        }
    };

    const triggerToast = (notif: KinetixNotification) => {
        if (isOpen.value) {
            return;
        }

        try {
            const options: any = {
                description: notif.description,
                duration: notif.duration || 4000,
            };

            if (notif.actions && notif.actions.length > 0) {
                const primaryAction = notif.actions[0];
                options.action = {
                    label: primaryAction.label,
                    onClick: () => {
                        handleAction(notif, primaryAction);
                    },
                };
            }

            if (notif.status === 'success') {
                toast.success(notif.title, options);

                return;
            }

            if (notif.status === 'warning') {
                toast.warning(notif.title, options);

                return;
            }

            if (notif.status === 'danger') {
                toast.error(notif.title, options);

                return;
            }

            toast.info(notif.title, options);
        } catch (e) {
            console.warn('Kinetix notifications toast error:', e);
        }
    };

    const syncFromProps = (newNotifs: any) => {
        if (!newNotifs || !Array.isArray(newNotifs)) {
            return;
        }

        if (isDatabaseMode.value) {
            notifications.value = newNotifs.map((notif: any) => ({
                id: notif.id,
                title: notif.title,
                description: notif.description,
                status: notif.status || 'info',
                duration: notif.duration,
                created_at: notif.created_at,
                read: notif.read ?? false,
                actions: notif.actions || [],
                type: notif.type,
            }));

            return;
        }

        let hasNew = false;
        newNotifs.forEach((notif: any) => {
            if (!notif.id) {
                return;
            }

            if (!seenNotificationIds.value.has(notif.id)) {
                seenNotificationIds.value.add(notif.id);

                const newNotif: KinetixNotification = {
                    id: notif.id,
                    title: notif.title,
                    description: notif.description,
                    status: notif.status || 'info',
                    duration: notif.duration,
                    created_at: notif.created_at || new Date().toISOString(),
                    read: false,
                    actions: notif.actions || [],
                    type: notif.type,
                };

                notifications.value.unshift(newNotif);
                triggerToast(newNotif);

                if (isInitialized.value) {
                    playNotificationSound();
                }

                hasNew = true;
            }
        });

        if (hasNew) {
            saveToStorage();
        }
    };

    const markAsRead = (id: string) => {
        const notif = notifications.value.find((n) => n.id === id);

        if (notif) {
            notif.read = true;
            saveToStorage();
        }

        if (isDatabaseMode.value) {
            sendRequest(
                `/${routePrefix.value}/notifications/${id}/read`,
                'POST',
            );
        }
    };

    const markAllAsRead = () => {
        notifications.value.forEach((n) => (n.read = true));
        saveToStorage();

        if (isDatabaseMode.value) {
            sendRequest(`/${routePrefix.value}/notifications/read-all`, 'POST');
        }
    };

    const removeNotification = (id: string) => {
        notifications.value = notifications.value.filter((n) => n.id !== id);
        saveToStorage();

        if (isDatabaseMode.value) {
            sendRequest(`/${routePrefix.value}/notifications/${id}`, 'DELETE');
        }
    };

    const clearAll = () => {
        notifications.value = [];
        saveToStorage();

        if (isDatabaseMode.value) {
            sendRequest(
                `/${routePrefix.value}/notifications/clear-all`,
                'DELETE',
            );
        }
    };

    const handleAction = (
        notif: KinetixNotification,
        action: KinetixAction,
    ) => {
        if (action.shouldMarkAsRead) {
            markAsRead(notif.id);
        }

        if (action.shouldMarkAsUnread) {
            const n = notifications.value.find((item) => item.id === notif.id);

            if (n) {
                n.read = false;
                saveToStorage();
            }
        }

        if (action.dispatchEvent) {
            window.dispatchEvent(
                new CustomEvent(`kinetix:${action.dispatchEvent}`, {
                    detail: action.dispatchData ?? {},
                    bubbles: true,
                }),
            );
        }

        if (action.inertiaVisit && action.url) {
            const { method = 'get', ...visitOptions } = action.inertiaVisit;
            router.visit(action.url, {
                method: method as any,
                ...visitOptions,
            });
        }

        if (!action.inertiaVisit && action.url) {
            if (action.shouldOpenInNewTab) {
                window.open(action.url, '_blank');
            }

            if (
                !action.shouldOpenInNewTab &&
                (action.url.startsWith('/') ||
                    action.url.startsWith(window.location.origin))
            ) {
                router.visit(action.url);
            }

            if (
                !action.shouldOpenInNewTab &&
                !action.url.startsWith('/') &&
                !action.url.startsWith(window.location.origin)
            ) {
                window.location.href = action.url;
            }
        }

        if (action.shouldClose) {
            removeNotification(notif.id);
        }
    };

    return {
        notifications,
        isOpen,
        seenNotificationIds,
        isInitialized,
        isDatabaseMode,
        routePrefix,
        userId,
        unreadCount,
        toggleDrawer,
        closeDrawer,
        saveToStorage,
        playNotificationSound,
        triggerToast,
        syncFromProps,
        markAsRead,
        markAllAsRead,
        removeNotification,
        clearAll,
        handleAction,
    };
});
