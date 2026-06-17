<script setup lang="ts">
import { usePage, router } from "@inertiajs/vue3";
import { useEchoNotification } from "@laravel/echo-vue";
import {
  Bell,
  CheckCircle2,
  AlertTriangle,
  XCircle,
  Info,
  X,
  Trash2,
  CheckCheck,
} from "@lucide/vue";
import { ref, computed, watch, onMounted } from "vue";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";

// Allow consumers to override the Echo channel model prefix.
// Defaults to `App.Models.User` which matches Laravel's standard User model.
// Change this if your app uses a different User model namespace.
const props = withDefaults(
  defineProps<{
    channelModel?: string;
  }>(),
  {
    channelModel: "App.Models.User",
  },
);

interface KinetixAction {
  name: string;
  label: string;
  icon?: string;
  iconPosition?: "before" | "after";
  url?: string;
  shouldOpenInNewTab: boolean;
  color: string;
  size?: "xs" | "sm" | "md" | "lg";
  viewType: "button" | "link";
  shouldClose: boolean;
  shouldMarkAsRead: boolean;
  shouldMarkAsUnread: boolean;
  dispatchEvent?: string;
  dispatchData?: Record<string, unknown>;
  inertiaVisit?: { method?: string; [key: string]: unknown };
}

interface KinetixNotification {
  id: string;
  title: string;
  description?: string;
  status: "info" | "success" | "warning" | "danger";
  duration?: number;
  created_at: string;
  read?: boolean;
  icon?: string;
  iconColor?: string;
  actions?: KinetixAction[];
}

const page = usePage();
const { t } = useI18n();
const notifications = ref<KinetixNotification[]>([]);
const isOpen = ref(false);
const seenNotificationIds = ref<Set<string>>(new Set());
const isInitialized = ref(false);

// Resolve notifications driver mode dynamically
const isDatabaseMode = computed(() => {
  return !!page.props.kinetix_config?.database;
});

// Resolve internal route prefix from shared config
const routePrefix = computed(() => {
  return (page.props.kinetix_config as any)?.route_prefix ?? "_kinetix";
});

// Authenticated user id (null when unauthenticated)
const userId = computed(() => (page.props.auth as any)?.user?.id ?? null);

// Build the private channel name: `{channelModel}.{userId}`
// e.g. "App.Models.User.1" → Echo subscribes to "private-App.Models.User.1"
const echoChannel = computed(() =>
  userId.value !== null ? `${props.channelModel}.${userId.value}` : null,
);

// Handler for incoming broadcast notifications
const onBroadcastNotification = (notification: Record<string, unknown>) => {
  const notif: KinetixNotification = {
    id: (notification.id as string) ?? crypto.randomUUID(),
    title: (notification.title as string) ?? "",
    description: notification.description as string | undefined,
    status: (notification.status as KinetixNotification["status"]) ?? "info",
    duration: notification.duration as number | undefined,
    created_at: (notification.created_at as string) ?? new Date().toISOString(),
    actions: (notification.actions as KinetixAction[]) ?? [],
  };

  triggerToast(notif);
  playNotificationSound();

  // In DB mode reload the shared prop list; in local mode append directly
  if (isDatabaseMode.value) {
    router.reload({
      only: ["kinetix_notifications"],
      onSuccess: () => {
        if (page.props.kinetix_notifications) {
          syncFromProps(page.props.kinetix_notifications);
        }
      },
    });
  } else {
    syncFromProps([notif]);
  }
};

// --- Real-time Echo listener ---
// useEchoNotification wraps Echo's private().notification() pattern.
// channelName is a plain string; the `dependencies` array (4th arg) tells
// the composable to re-subscribe whenever echoChannel changes — i.e. when
// the authenticated userId changes (login / impersonation).
// When echoChannel is null (unauthenticated) we skip via the watch below.
const { listen: echoListen, stopListening: echoStop } = useEchoNotification<
  Record<string, unknown>
>(
  echoChannel.value ?? `${props.channelModel}.0`, // initial placeholder
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
    } else {
      echoStop();
    }
  },
  { immediate: true },
);

// Compute unread count
const unreadCount = computed(() => {
  return notifications.value.filter((n) => !n.read).length;
});

// Toggle dropdown open/close
const toggleDropdown = () => {
  isOpen.value = !isOpen.value;
};

// Close dropdown when clicking outside
const closeDropdown = () => {
  isOpen.value = false;
};

// Save to localStorage (only used in local driver mode)
const saveToStorage = () => {
  if (!isDatabaseMode.value) {
    localStorage.setItem(
      "kinetix_notifications_list",
      JSON.stringify(notifications.value),
    );
    localStorage.setItem(
      "kinetix_seen_ids",
      JSON.stringify(Array.from(seenNotificationIds.value)),
    );
  }
};

// Play notification sound if enabled
const playNotificationSound = () => {
  const soundConfig = page.props.kinetix_config?.sound;

  if (soundConfig?.enabled && soundConfig?.path) {
    try {
      const audio = new Audio(soundConfig.path);
      audio.play();
    } catch (e) {
      console.warn("Failed to play Kinetix notification audio:", e);
    }
  }
};

// Retrieve CSRF token from cookies
const getXsrfToken = () => {
  const cookies = document.cookie.split(";");

  for (const cookie of cookies) {
    const [name, value] = cookie.trim().split("=");

    if (name === "XSRF-TOKEN") {
      return decodeURIComponent(value);
    }
  }

  return "";
};

// Execute background XHR request and reload Inertia props (avoids raw JSON Inertia modal)
const sendRequest = async (url: string, method: string) => {
  try {
    const response = await fetch(url, {
      method,
      headers: {
        "Content-Type": "application/json",
        "X-XSRF-TOKEN": getXsrfToken(),
      },
    });

    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }

    // Reload notifications prop to update the frontend state cleanly
    router.reload({
      only: ["kinetix_notifications"],
      onSuccess: () => {
        if (page.props.kinetix_notifications) {
          syncFromProps(page.props.kinetix_notifications);
        }
      },
    });
  } catch (e) {
    console.error(`Kinetix notifications request failed: ${method} ${url}`, e);
  }
};

// Trigger vue-sonner toast
const triggerToast = (notif: KinetixNotification) => {
  try {
    const options = {
      description: notif.description,
      duration: notif.duration || 4000,
    };

    switch (notif.status) {
      case "success":
        toast.success(notif.title, options);
        break;
      case "warning":
        toast.warning(notif.title, options);
        break;
      case "danger":
        toast.error(notif.title, options);
        break;
      default:
        toast.info(notif.title, options);
        break;
    }
  } catch (e) {
    console.warn("Kinetix notifications toast error:", e);
  }
};

// Synchronize notification list from props based on driver
const syncFromProps = (newNotifs: any) => {
  if (!newNotifs || !Array.isArray(newNotifs)) {
    return;
  }

  if (isDatabaseMode.value) {
    // Database mode completely synchronizes with the server-side list
    notifications.value = newNotifs.map((notif: any) => ({
      id: notif.id,
      title: notif.title,
      description: notif.description,
      status: notif.status || "info",
      duration: notif.duration,
      created_at: notif.created_at,
      read: notif.read ?? false,
      actions: notif.actions || [],
    }));
  } else {
    // Local mode appends new entries to local state
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
          status: notif.status || "info",
          duration: notif.duration,
          created_at: notif.created_at || new Date().toISOString(),
          read: false,
          actions: notif.actions || [],
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
  }
};

// Mark a single notification as read
const markAsRead = (id: string) => {
  const notif = notifications.value.find((n) => n.id === id);

  if (notif) {
    notif.read = true;
    saveToStorage();
  }

  if (isDatabaseMode.value) {
    sendRequest(`/${routePrefix.value}/notifications/${id}/read`, "POST");
  }
};

// Mark all as read
const markAllAsRead = () => {
  notifications.value.forEach((n) => (n.read = true));
  saveToStorage();

  if (isDatabaseMode.value) {
    sendRequest(`/${routePrefix.value}/notifications/read-all`, "POST");
  }
};

// Clear a single notification
const removeNotification = (id: string) => {
  notifications.value = notifications.value.filter((n) => n.id !== id);
  saveToStorage();

  if (isDatabaseMode.value) {
    sendRequest(`/${routePrefix.value}/notifications/${id}`, "DELETE");
  }
};

// Clear all notifications
const clearAll = () => {
  notifications.value = [];
  saveToStorage();

  if (isDatabaseMode.value) {
    sendRequest(`/${routePrefix.value}/notifications/clear-all`, "DELETE");
  }
};

// Get Tailwind classes for action buttons
const getActionClass = (action: KinetixAction) => {
  if (action.viewType === "link") {
    switch (action.color) {
      case "primary":
        return "text-blue-600 dark:text-blue-400 hover:underline px-0 bg-transparent shadow-none";
      case "danger":
        return "text-red-600 dark:text-red-400 hover:underline px-0 bg-transparent shadow-none";
      case "warning":
        return "text-amber-600 dark:text-amber-400 hover:underline px-0 bg-transparent shadow-none";
      case "success":
        return "text-green-600 dark:text-green-400 hover:underline px-0 bg-transparent shadow-none";
      default:
        return "text-neutral-500 hover:underline px-0 bg-transparent shadow-none";
    }
  }

  // Button viewType classes
  switch (action.color) {
    case "primary":
      return "bg-blue-600 hover:bg-blue-700 text-white dark:bg-blue-500 dark:hover:bg-blue-600";
    case "danger":
      return "bg-red-600 hover:bg-red-700 text-white dark:bg-red-500 dark:hover:bg-red-600";
    case "warning":
      return "bg-amber-500 hover:bg-amber-600 text-white dark:bg-amber-500 dark:hover:bg-amber-600";
    case "success":
      return "bg-green-600 hover:bg-green-700 text-white dark:bg-green-500 dark:hover:bg-green-600";
    default:
      return "bg-neutral-100 text-neutral-800 hover:bg-neutral-200 dark:bg-neutral-800 dark:text-neutral-200 dark:hover:bg-neutral-700";
  }
};

// Handle interactive actions click
const handleAction = (notif: KinetixNotification, action: KinetixAction) => {
  // 1. Mark notification read/unread if requested
  if (action.shouldMarkAsRead) {
    markAsRead(notif.id);
  }

  if (action.shouldMarkAsUnread) {
    const n = notifications.value.find((n) => n.id === notif.id);

    if (n) {
      n.read = false;
      saveToStorage();
    }
  }

  // 2. Dispatch a custom browser event if configured
  if (action.dispatchEvent) {
    window.dispatchEvent(
      new CustomEvent(`kinetix:${action.dispatchEvent}`, {
        detail: action.dispatchData ?? {},
        bubbles: true,
      }),
    );
  }

  // 3. Handle Inertia visit or plain URL navigation
  if (action.inertiaVisit && action.url) {
    const { method = "get", ...visitOptions } = action.inertiaVisit;
    router.visit(action.url, { method: method as any, ...visitOptions });
  } else if (action.url) {
    if (action.shouldOpenInNewTab) {
      window.open(action.url, "_blank");
    } else if (
      action.url.startsWith("/") ||
      action.url.startsWith(window.location.origin)
    ) {
      router.visit(action.url);
    } else {
      window.location.href = action.url;
    }
  }

  // 4. Close the notification element if requested
  if (action.shouldClose) {
    removeNotification(notif.id);
  }
};

// Watch Inertia shared props for kinetix_notifications
watch(
  () => page.props.kinetix_notifications,
  (newVal) => {
    syncFromProps(newVal);
  },
  { immediate: true, deep: true },
);

// Format relative time (using vue-i18n keys)
const formatTime = (dateStr: string) => {
  try {
    const date = new Date(dateStr);
    const now = new Date();
    const diffMs = now.getTime() - date.getTime();
    const diffMins = Math.floor(diffMs / 60000);

    if (diffMins < 1) {
      return t("kinetix.just_now");
    }

    if (diffMins < 60) {
      return t("kinetix.minutes_ago", { minutes: diffMins });
    }

    const diffHours = Math.floor(diffMins / 60);

    if (diffHours < 24) {
      return t("kinetix.hours_ago", { hours: diffHours });
    }

    return date.toLocaleDateString(undefined, {
      month: "short",
      day: "numeric",
    });
  } catch {
    return "";
  }
};

// On mount, restore/listen based on driver
onMounted(() => {
  if (!isDatabaseMode.value) {
    try {
      const savedNotifs = localStorage.getItem("kinetix_notifications_list");
      const savedSeen = localStorage.getItem("kinetix_seen_ids");

      if (savedNotifs) {
        notifications.value = JSON.parse(savedNotifs);
      }

      if (savedSeen) {
        const arr = JSON.parse(savedSeen);
        seenNotificationIds.value = new Set(arr);
      }
    } catch (e) {
      console.error("Failed to restore Kinetix notifications from storage", e);
    }
  } else {
    if (page.props.kinetix_notifications) {
      syncFromProps(page.props.kinetix_notifications);
    }
  }

  // Set initialized to true after small delay to avoid playing audio for initial list load
  setTimeout(() => {
    isInitialized.value = true;
  }, 500);

  // Add click outside handler to close dropdown
  const handleOutsideClick = (e: MouseEvent) => {
    const target = e.target as HTMLElement;

    if (isOpen.value && !target.closest(".kinetix-notifications-container")) {
      closeDropdown();
    }
  };

  document.addEventListener("click", handleOutsideClick);

  return () => {
    document.removeEventListener("click", handleOutsideClick);
  };
});
</script>

<template>
  <div class="kinetix-notifications-container relative flex items-center">
    <!-- Bell Trigger Button -->
    <button
      @click="toggleDropdown"
      class="relative flex h-9 w-9 items-center justify-center rounded-full hover:bg-neutral-100 dark:hover:bg-neutral-800 transition-colors focus:outline-none"
      :aria-label="t('kinetix.notifications')"
    >
      <Bell class="h-5 w-5 text-neutral-600 dark:text-neutral-300" />
      <span
        v-if="unreadCount > 0"
        class="absolute top-1.5 right-1.5 flex h-4 w-4 items-center justify-center rounded-full bg-red-500 text-[10px] font-medium text-white ring-2 ring-white dark:ring-neutral-900 animate-pulse"
      >
        {{ unreadCount }}
      </span>
    </button>

    <!-- Dropdown Card -->
    <Transition
      enter-active-class="transition duration-150 ease-out"
      enter-from-class="transform scale-95 opacity-0"
      enter-to-class="transform scale-100 opacity-100"
      leave-active-class="transition duration-100 ease-in"
      leave-from-class="transform scale-100 opacity-100"
      leave-to-class="transform scale-95 opacity-0"
    >
      <div
        v-if="isOpen"
        class="absolute right-0 top-11 z-50 w-80 md:w-96 rounded-xl border border-neutral-200 bg-white p-4 shadow-xl dark:border-neutral-800 dark:bg-neutral-950"
      >
        <!-- Header -->
        <div
          class="flex items-center justify-between border-b border-neutral-100 pb-3 dark:border-neutral-900"
        >
          <div class="flex items-center gap-2">
            <h3 class="font-semibold text-neutral-900 dark:text-neutral-500">
              {{ t("kinetix.notifications") }}
            </h3>
            <span
              v-if="unreadCount > 0"
              class="rounded-full bg-neutral-100 px-2 py-0.5 text-xs text-neutral-600 dark:bg-neutral-900 dark:text-neutral-400"
            >
              {{ unreadCount }} {{ t("kinetix.new_notifications") }}
            </span>
          </div>
          <div class="flex items-center gap-1.5">
            <button
              v-if="unreadCount > 0"
              @click="markAllAsRead"
              class="flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-blue-600 hover:bg-blue-50 dark:text-blue-400 dark:hover:bg-blue-950/30 transition-colors cursor-pointer"
              :title="t('kinetix.mark_all_as_read')"
            >
              <CheckCheck class="h-3.5 w-3.5" />
            </button>
            <button
              v-if="notifications.length > 0"
              @click="clearAll"
              class="flex items-center gap-1 rounded px-2 py-1 text-xs font-medium text-neutral-500 hover:bg-neutral-100 dark:text-neutral-400 dark:hover:bg-neutral-900 transition-colors cursor-pointer"
              :title="t('kinetix.clear_all')"
            >
              <Trash2 class="h-3.5 w-3.5" />
            </button>
          </div>
        </div>

        <!-- Notifications List -->
        <div
          class="mt-2 max-h-[350px] overflow-y-auto divide-y divide-neutral-100 dark:divide-neutral-900 pr-1"
        >
          <div
            v-if="notifications.length === 0"
            class="flex flex-col items-center justify-center py-8 text-center"
          >
            <div
              class="flex h-12 w-12 items-center justify-center rounded-full bg-neutral-50 dark:bg-neutral-900 text-neutral-400"
            >
              <Bell class="h-6 w-6" />
            </div>
            <p
              class="mt-2 text-sm font-medium text-neutral-900 dark:text-neutral-500"
            >
              {{ t("kinetix.no_notifications") }}
            </p>
            <p class="text-xs text-neutral-500">
              {{ t("kinetix.notifications_appear_here") }}
            </p>
          </div>

          <div
            v-for="notif in notifications"
            :key="notif.id"
            class="group relative flex flex-col gap-1 py-3 transition-colors hover:bg-neutral-50/50 dark:hover:bg-neutral-900/30 rounded-lg px-2"
            :class="{ 'bg-blue-50/20 dark:bg-blue-950/10': !notif.read }"
          >
            <div class="flex gap-3">
              <!-- Status Icon -->
              <div class="mt-0.5 flex-shrink-0">
                <CheckCircle2
                  v-if="notif.status === 'success'"
                  class="h-5 w-5 text-green-500"
                />
                <AlertTriangle
                  v-else-if="notif.status === 'warning'"
                  class="h-5 w-5 text-amber-500"
                />
                <XCircle
                  v-else-if="notif.status === 'danger'"
                  class="h-5 w-5 text-red-500"
                />
                <Info v-else class="h-5 w-5 text-blue-500" />
              </div>

              <!-- Content -->
              <div class="flex-1 min-w-0 pr-4" @click="markAsRead(notif.id)">
                <div class="flex items-start justify-between gap-1">
                  <p
                    class="text-sm font-medium text-neutral-900 dark:text-neutral-500 break-words"
                    :class="{ 'font-semibold': !notif.read }"
                  >
                    {{ notif.title }}
                  </p>
                </div>
                <p
                  v-if="notif.description"
                  class="mt-0.5 text-xs text-neutral-500 dark:text-neutral-400 break-words leading-normal"
                >
                  {{ notif.description }}
                </p>

                <!-- Render actions below description -->
                <div
                  v-if="notif.actions && notif.actions.length > 0"
                  class="mt-2 flex flex-wrap items-center gap-1.5"
                >
                  <button
                    v-for="action in notif.actions"
                    :key="action.name"
                    @click.stop="handleAction(notif, action)"
                    class="inline-flex items-center gap-1 rounded px-2.5 py-1 text-[11px] font-semibold shadow-sm transition-colors cursor-pointer"
                    :class="getActionClass(action)"
                  >
                    {{ action.label }}
                  </button>
                </div>

                <span class="mt-1.5 block text-[10px] text-neutral-500">
                  {{ formatTime(notif.created_at) }}
                </span>
              </div>

              <!-- Action Buttons (on hover) -->
              <div
                class="absolute right-2 top-3 flex items-center gap-1 opacity-0 group-hover:opacity-100 transition-opacity"
              >
                <button
                  @click.stop="removeNotification(notif.id)"
                  class="rounded p-1 text-neutral-400 hover:bg-neutral-100 hover:text-neutral-600 dark:hover:bg-neutral-800 transition-colors cursor-pointer"
                  :title="t('kinetix.delete')"
                >
                  <X class="h-3.5 w-3.5" />
                </button>
              </div>

              <!-- Unread Dot -->
              <div
                v-if="!notif.read"
                class="absolute right-3 bottom-4 h-2 w-2 rounded-full bg-blue-500"
              ></div>
            </div>
          </div>
        </div>
      </div>
    </Transition>
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
