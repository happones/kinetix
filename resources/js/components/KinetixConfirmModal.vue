<script setup lang="ts">
import { AlertTriangle, X } from "@lucide/vue";
import { onBeforeUnmount, watch } from "vue";
import { useI18n } from "vue-i18n";

const props = withDefaults(
  defineProps<{
    open: boolean;
    heading?: string | null;
    description?: string | null;
    icon?: string | null;
    color?: string | null;
    submitLabel?: string | null;
    cancelLabel?: string | null;
  }>(),
  {
    heading: null,
    description: null,
    icon: null,
    color: "danger",
    submitLabel: null,
    cancelLabel: null,
  },
);

const emit = defineEmits<{
  (e: "update:open", value: boolean): void;
  (e: "confirm"): void;
  (e: "cancel"): void;
}>();

const { t } = useI18n();

const cancel = () => {
  emit("update:open", false);
  emit("cancel");
};

const confirm = () => {
  emit("update:open", false);
  emit("confirm");
};

// Escape-to-cancel. The listener is added only while the modal is open and is
// always removed on unmount, so no handler leaks across the component lifecycle.
const onKeydown = (event: KeyboardEvent) => {
  if (event.key === "Escape") {
    cancel();
  }
};

watch(
  () => props.open,
  (isOpen) => {
    if (typeof window === "undefined") {
      return;
    }

    if (isOpen) {
      window.addEventListener("keydown", onKeydown);

      return;
    }

    window.removeEventListener("keydown", onKeydown);
  },
);

onBeforeUnmount(() => {
  if (typeof window !== "undefined") {
    window.removeEventListener("keydown", onKeydown);
  }
});

const getConfirmButtonClass = (color?: string | null) => {
  if (color === "danger") {
    return "bg-rose-600 text-white hover:bg-rose-600/90";
  }

  if (color === "success") {
    return "bg-emerald-600 text-white hover:bg-emerald-600/90";
  }

  if (color === "warning") {
    return "bg-amber-500 text-white hover:bg-amber-500/90";
  }

  return "bg-primary text-primary-foreground hover:bg-primary/90";
};

const getIconWrapperClass = (color?: string | null) => {
  if (color === "danger") {
    return "bg-rose-50 text-rose-600 dark:bg-rose-950/40 dark:text-rose-400";
  }

  if (color === "success") {
    return "bg-emerald-50 text-emerald-600 dark:bg-emerald-950/40 dark:text-emerald-400";
  }

  if (color === "warning") {
    return "bg-amber-50 text-amber-600 dark:bg-amber-950/40 dark:text-amber-400";
  }

  return "bg-muted text-muted-foreground";
};
</script>

<template>
  <Teleport to="body">
    <Transition
      enter-active-class="transition-opacity duration-150"
      enter-from-class="opacity-0"
      leave-active-class="transition-opacity duration-150"
      leave-to-class="opacity-0"
    >
      <div
        v-if="open"
        class="fixed inset-0 z-[100] flex items-center justify-center p-4"
        role="dialog"
        aria-modal="true"
      >
        <!-- Overlay -->
        <div
          class="absolute inset-0 bg-black/50 backdrop-blur-sm"
          @click="cancel"
        />

        <!-- Dialog -->
        <div
          class="relative w-full max-w-md rounded-xl border border-border bg-popover shadow-2xl"
        >
          <button
            type="button"
            class="absolute right-4 top-4 text-muted-foreground hover:text-foreground transition-colors"
            :aria-label="cancelLabel ?? t('kinetix.cancel')"
            @click="cancel"
          >
            <X class="h-4 w-4" />
          </button>

          <div class="p-6">
            <div class="flex items-start gap-4">
              <span
                class="flex h-10 w-10 shrink-0 items-center justify-center rounded-full"
                :class="getIconWrapperClass(color)"
              >
                <AlertTriangle class="h-5 w-5" />
              </span>

              <div class="flex-1 pt-1">
                <h2
                  class="text-base font-semibold leading-none tracking-tight text-foreground"
                >
                  {{ heading ?? t("kinetix.confirm_heading") }}
                </h2>
                <p
                  v-if="description"
                  class="mt-2 text-sm text-muted-foreground"
                >
                  {{ description }}
                </p>
              </div>
            </div>

            <div class="mt-6 flex justify-end gap-3">
              <button
                type="button"
                class="inline-flex h-9 items-center justify-center rounded-md border border-border bg-popover px-4 py-2 text-sm font-medium text-foreground transition-colors hover:bg-accent"
                @click="cancel"
              >
                {{ cancelLabel ?? t("kinetix.cancel") }}
              </button>
              <button
                type="button"
                class="inline-flex h-9 items-center justify-center rounded-md px-4 py-2 text-sm font-medium transition-colors focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-offset-2"
                :class="getConfirmButtonClass(color)"
                @click="confirm"
              >
                {{ submitLabel ?? t("kinetix.confirm") }}
              </button>
            </div>
          </div>
        </div>
      </div>
    </Transition>
  </Teleport>
</template>
