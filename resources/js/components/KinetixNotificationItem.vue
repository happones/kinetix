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
import { useNotificationsStore } from '@/stores/kinetixNotifications';
import type { KinetixNotification } from '@/types/kinetix';

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
        return 'text-muted-foreground';
    }

    switch (notif.status) {
        case 'success':
            return 'text-success';
        case 'warning':
            return 'text-warning';
        case 'danger':
            return 'text-destructive';
        case 'info':
            return 'text-info';
        default:
            return 'text-muted-foreground';
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
        class="gap-3 px-6 py-4 relative flex transition-colors hover:bg-accent/50"
        :class="[
            notif.read ? '' : 'border-l-2 border-primary bg-primary/[0.02]',
        ]"
    >
        <!-- Icon -->
        <component
            :is="getNotificationIcon(notif)"
            class="mt-0.5 h-5 w-5 shrink-0"
            :class="getNotificationIconClass(notif)"
        />

        <!-- Content -->
        <div class="min-w-0 flex-1" @click="store.markAsRead(notif.id)">
            <p
                class="text-sm leading-tight font-semibold break-words text-foreground"
                :class="{ 'font-bold': !notif.read }"
            >
                {{ notif.title }}
            </p>
            <p class="mt-0.5 text-xs text-muted-foreground">
                {{ formatTime(notif.created_at) }}
            </p>
            <p
                v-if="notif.description"
                class="mt-1.5 text-sm leading-normal break-words text-muted-foreground"
            >
                {{ notif.description }}
            </p>

            <!-- Actions -->
            <div
                v-if="notif.actions && notif.actions.length > 0"
                class="mt-2 gap-4 flex flex-wrap items-center"
                @click.stop
            >
                <button
                    v-for="action in notif.actions"
                    :key="action.name"
                    @click.stop="store.handleAction(notif, action)"
                    class="text-sm font-medium cursor-pointer transition-colors hover:underline"
                    :class="[
                        action.color === 'danger'
                            ? 'text-destructive hover:text-destructive/80'
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
            class="top-4 right-4 absolute cursor-pointer text-muted-foreground/60 transition-colors hover:text-foreground"
            :aria-label="t('kinetix.delete')"
        >
            <X class="h-4 w-4" />
        </button>
    </div>
</template>
