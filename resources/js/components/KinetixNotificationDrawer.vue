<script setup lang="ts">
import { X, Bell } from '@lucide/vue';
import { storeToRefs } from 'pinia';
import { onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useNotificationsStore } from '@/stores/notifications';
import KinetixNotificationItem from './KinetixNotificationItem.vue';

const store = useNotificationsStore();
const { notifications, isOpen, unreadCount } = storeToRefs(store);
const { t } = useI18n();

const isMounted = ref(false);
onMounted(() => {
    isMounted.value = true;
});
</script>

<template>
    <!-- Drawer Portal (Teleport to body) -->
    <Teleport v-if="isMounted" to="body">
        <!-- Backdrop -->
        <Transition
            enter-active-class="transition-opacity ease-out duration-300"
            enter-from-class="opacity-0"
            enter-to-class="opacity-100"
            leave-active-class="transition-opacity ease-in duration-200"
            leave-from-class="opacity-100"
            leave-to-class="opacity-0"
        >
            <div
                v-if="isOpen"
                class="inset-0 bg-black/40 backdrop-blur-sm fixed z-50"
                @click="store.closeDrawer"
            ></div>
        </Transition>

        <!-- Panel -->
        <Transition
            enter-active-class="transform transition ease-out duration-300"
            enter-from-class="translate-x-full"
            enter-to-class="translate-x-0"
            leave-active-class="transform transition ease-in duration-200"
            leave-from-class="translate-x-0"
            leave-to-class="translate-x-full"
        >
            <div
                v-if="isOpen"
                class="inset-y-0 right-0 max-w-md shadow-2xl fixed z-50 flex w-full flex-col border-l bg-popover"
            >
                <!-- Header -->
                <div
                    class="gap-3 px-6 py-4 flex flex-col border-b border-border text-left"
                >
                    <div class="flex items-center justify-between">
                        <h2
                            class="gap-2 text-lg font-bold flex items-center text-foreground"
                        >
                            {{ t('kinetix.notifications') }}
                            <span
                                v-if="unreadCount > 0"
                                class="h-5 min-w-5 px-1.5 text-xs font-semibold flex items-center justify-center rounded-full bg-primary text-primary-foreground"
                            >
                                {{ unreadCount }}
                            </span>
                        </h2>

                        <!-- Close button -->
                        <button
                            @click="store.closeDrawer"
                            class="rounded-md text-muted-foreground hover:text-foreground focus:outline-none"
                            aria-label="Cerrar"
                        >
                            <X class="h-5 w-5" />
                        </button>
                    </div>

                    <!-- Global actions -->
                    <div class="gap-4 flex items-center">
                        <button
                            @click="store.markAllAsRead"
                            :disabled="unreadCount === 0"
                            class="text-sm font-medium cursor-pointer text-primary transition-colors hover:text-primary/80 disabled:opacity-40"
                        >
                            {{ t('kinetix.mark_all_as_read') }}
                        </button>
                        <button
                            @click="store.clearAll"
                            :disabled="notifications.length === 0"
                            class="text-sm font-medium cursor-pointer text-destructive transition-colors hover:text-destructive/80 disabled:opacity-40"
                        >
                            {{ t('kinetix.clear_all') }}
                        </button>
                    </div>
                </div>

                <!-- List / Empty state -->
                <div class="flex-1 divide-y divide-border overflow-y-auto">
                    <div
                        v-if="notifications.length === 0"
                        class="gap-2 px-6 py-20 flex h-full flex-col items-center justify-center text-center"
                    >
                        <div
                            class="h-12 w-12 flex items-center justify-center rounded-full bg-muted"
                        >
                            <Bell class="h-6 w-6 text-muted-foreground" />
                        </div>
                        <p class="text-sm font-medium text-foreground">
                            {{ t('kinetix.no_notifications') }}
                        </p>
                        <p class="text-xs text-muted-foreground">
                            {{ t('kinetix.notifications_appear_here') }}
                        </p>
                    </div>

                    <div v-else class="divide-y divide-border">
                        <KinetixNotificationItem
                            v-for="notif in notifications"
                            :key="notif.id"
                            :notif="notif"
                        />
                    </div>
                </div>
            </div>
        </Transition>
    </Teleport>
</template>
