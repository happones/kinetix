<script setup lang="ts">
import { X, Bell } from "@lucide/vue";
import { storeToRefs } from "pinia";
import { useI18n } from "vue-i18n";
import { useNotificationsStore } from "@/stores/notifications";
import KinetixNotificationItem from "./KinetixNotificationItem.vue";

const store = useNotificationsStore();
const { notifications, isOpen, unreadCount } = storeToRefs(store);
const { t } = useI18n();
</script>

<template>
  <!-- Drawer Portal (Teleport to body) -->
  <Teleport to="body">
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
        class="fixed inset-0 z-50 bg-black/40 backdrop-blur-sm"
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
        class="fixed inset-y-0 right-0 z-50 flex w-full max-w-md flex-col border-l bg-popover shadow-2xl"
      >
        <!-- Header -->
        <div
          class="flex flex-col gap-3 border-b border-border px-6 py-4 text-left"
        >
          <div class="flex items-center justify-between">
            <h2
              class="flex items-center gap-2 text-lg font-bold text-foreground"
            >
              {{ t("kinetix.notifications") }}
              <span
                v-if="unreadCount > 0"
                class="flex h-5 min-w-5 items-center justify-center rounded-full bg-primary px-1.5 text-xs font-semibold text-primary-foreground"
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
          <div class="flex items-center gap-4">
            <button
              @click="store.markAllAsRead"
              :disabled="unreadCount === 0"
              class="cursor-pointer text-sm font-medium text-primary transition-colors hover:text-primary/80 disabled:opacity-40"
            >
              {{ t("kinetix.mark_all_as_read") }}
            </button>
            <button
              @click="store.clearAll"
              :disabled="notifications.length === 0"
              class="cursor-pointer text-sm font-medium text-red-500 transition-colors hover:text-red-600 disabled:opacity-40"
            >
              {{ t("kinetix.clear_all") }}
            </button>
          </div>
        </div>

        <!-- List / Empty state -->
        <div class="flex-1 divide-y divide-border overflow-y-auto">
          <div
            v-if="notifications.length === 0"
            class="flex h-full flex-col items-center justify-center gap-2 px-6 py-20 text-center"
          >
            <div
              class="flex h-12 w-12 items-center justify-center rounded-full bg-muted"
            >
              <Bell class="h-6 w-6 text-muted-foreground" />
            </div>
            <p class="text-sm font-medium text-foreground">
              {{ t("kinetix.no_notifications") }}
            </p>
            <p class="text-xs text-muted-foreground">
              {{ t("kinetix.notifications_appear_here") }}
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
