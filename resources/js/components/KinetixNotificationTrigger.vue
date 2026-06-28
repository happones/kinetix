<script setup lang="ts">
import { Bell } from '@lucide/vue';
import { storeToRefs } from 'pinia';
import { useI18n } from 'vue-i18n';
import { buttonVariants } from '@/composables/useShadcnVariants';
import { useNotificationsStore } from '@/stores/notifications';

const store = useNotificationsStore();
const { unreadCount } = storeToRefs(store);
const { t } = useI18n();
</script>

<template>
    <button
        type="button"
        :class="buttonVariants({ variant: 'ghost', size: 'icon-sm' })"
        class="relative"
        :aria-label="t('kinetix.notifications')"
        @click="store.toggleDrawer"
    >
        <Bell class="size-[1.2rem]" />
        <span
            v-if="unreadCount > 0"
            class="-top-1 -right-1 px-1 font-semibold absolute flex min-w-[18px] items-center justify-center rounded-full bg-primary text-[10px] leading-[18px] text-primary-foreground"
        >
            {{ unreadCount > 99 ? '99+' : unreadCount }}
        </span>
    </button>
</template>
