<script setup lang="ts">
import { Bell } from '@lucide/vue';
import { storeToRefs } from 'pinia';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    buttonVariants,
    triggerCountBadgeClass,
} from '@/composables/useKinetixShadcnVariants';
import { useNotificationsStore } from '@/stores/kinetixNotifications';

const store = useNotificationsStore();
const { unreadCount } = storeToRefs(store);
const { t } = useI18n();

/** The badge is a number on screen; the trigger has to say what it counts. */
const label = computed(() =>
    unreadCount.value > 0
        ? `${t('kinetix.notifications')} — ${t('kinetix.unread_count', { count: unreadCount.value })}`
        : t('kinetix.notifications'),
);
</script>

<template>
    <!-- `outline` + `icon-sm`, like every other header trigger (announcements,
         accessibility, dark mode, language) — a `ghost` bell was the odd one
         out, sitting borderless next to four bordered buttons. -->
    <button
        type="button"
        :class="buttonVariants({ variant: 'outline', size: 'icon-sm' })"
        class="relative"
        :aria-label="label"
        @click="store.toggleDrawer"
    >
        <Bell class="size-[1.2rem]" />
        <span
            v-if="unreadCount > 0"
            aria-hidden="true"
            :class="triggerCountBadgeClass"
        >
            {{ unreadCount > 99 ? '99+' : unreadCount }}
        </span>
    </button>
</template>
