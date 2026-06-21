<script setup lang="ts">
import { Bell } from "@lucide/vue";
import { storeToRefs } from "pinia";
import { useI18n } from "vue-i18n";
import { useNotificationsStore } from "@/stores/notifications";

const store = useNotificationsStore();
const { unreadCount } = storeToRefs(store);
const { t } = useI18n();
</script>

<template>
  <button
    @click="store.toggleDrawer"
    class="relative flex h-9 w-9 items-center justify-center rounded-full transition-colors hover:bg-accent focus:outline-none"
    :aria-label="t('kinetix.notifications')"
  >
    <Bell class="h-5 w-5 text-muted-foreground" />
    <span
      v-if="unreadCount > 0"
      class="absolute -top-1 -right-1 flex min-w-[18px] items-center justify-center rounded-full bg-primary px-1 text-[10px] leading-[18px] font-semibold text-primary-foreground"
    >
      {{ unreadCount > 99 ? "99+" : unreadCount }}
    </span>
  </button>
</template>
