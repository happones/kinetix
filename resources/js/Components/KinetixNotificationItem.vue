<script setup lang="ts">
import {
    Bell,
    CheckCircle2,
    AlertTriangle,
    XCircle,
    Info,
    X,
    ShoppingBag,
} from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { useNotificationsStore } from '@/stores/notifications';
import type { KinetixNotification } from '@/types';

defineProps<{
    notif: KinetixNotification;
}>();

const store = useNotificationsStore();
const { t } = useI18n();

// Resolve the Lucide icon depending on type and status
const getNotificationIcon = (notif: KinetixNotification) => {
    if (notif.type === 'order') {
        return ShoppingBag;
    }

    switch (notif.status) {
        case 'success':
            return CheckCircle2;
        case 'warning':
            return AlertTriangle;
        case 'danger':
            return XCircle;
        case 'info':
            return Info;
        default:
            return Bell;
    }
};

// Resolve custom colors/classes for the notification icon
const getNotificationIconClass = (notif: KinetixNotification) => {
    if (notif.type === 'order') {
        return 'text-neutral-500 dark:text-neutral-400';
    }

    switch (notif.status) {
        case 'success':
            return 'text-green-500';
        case 'warning':
            return 'text-amber-500';
        case 'danger':
            return 'text-red-500';
        case 'info':
            return 'text-blue-500';
        default:
            return 'text-neutral-500 dark:text-neutral-400';
    }
};

// Format relative time (using vue-i18n keys)
const formatTime = (dateStr: string) => {
    try {
        const date = new Date(dateStr);
        const now = new Date();
        const diffMs = now.getTime() - date.getTime();
        const diffMins = Math.floor(diffMs / 60000);

        if (diffMins < 1) {
            return t('kinetix.just_now');
        }

        if (diffMins < 60) {
            return t('kinetix.minutes_ago', { minutes: diffMins });
        }

        const diffHours = Math.floor(diffMins / 60);

        if (diffHours < 24) {
            return t('kinetix.hours_ago', { hours: diffHours });
        }

        return date.toLocaleDateString(undefined, {
            month: 'short',
            day: 'numeric',
        });
    } catch {
        return '';
    }
};
</script>

<template>
    <div
        class="relative flex gap-3 px-6 py-4 transition-colors hover:bg-neutral-50/50 dark:hover:bg-neutral-900/30"
        :class="[
            notif.read
                ? ''
                : 'border-l-2 border-primary bg-primary/[0.02]',
        ]"
    >
        <!-- Icon -->
        <component
            :is="getNotificationIcon(notif)"
            class="mt-0.5 h-5 w-5 shrink-0"
            :class="getNotificationIconClass(notif)"
        />

        <!-- Content -->
        <div
            class="min-w-0 flex-1"
            @click="store.markAsRead(notif.id)"
        >
            <p
                class="text-sm leading-tight font-semibold break-words text-neutral-900 dark:text-neutral-100"
                :class="{ 'font-bold': !notif.read }"
            >
                {{ notif.title }}
            </p>
            <p
                class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400"
            >
                {{ formatTime(notif.created_at) }}
            </p>
            <p
                v-if="notif.description"
                class="mt-1.5 text-sm leading-normal break-words text-neutral-500 dark:text-neutral-400"
            >
                {{ notif.description }}
            </p>

            <!-- Actions -->
            <div
                v-if="notif.actions && notif.actions.length > 0"
                class="mt-2 flex flex-wrap items-center gap-4"
                @click.stop
            >
                <button
                    v-for="action in notif.actions"
                    :key="action.name"
                    @click.stop="store.handleAction(notif, action)"
                    class="cursor-pointer text-sm font-medium transition-colors hover:underline"
                    :class="[
                        action.color === 'danger'
                            ? 'text-red-500 hover:text-red-600'
                            : 'text-primary hover:text-primary/80',
                    ]"
                >
                    {{ action.label }}
                </button>
            </div>
        </div>

        <!-- Close Button -->
        <button
            @click.stop="store.removeNotification(notif.id)"
            class="absolute top-4 right-4 cursor-pointer text-neutral-400/60 transition-colors hover:text-neutral-950 dark:text-neutral-500/60 dark:hover:text-neutral-200"
            :aria-label="t('kinetix.delete')"
        >
            <X class="h-4 w-4" />
        </button>
    </div>
</template>
