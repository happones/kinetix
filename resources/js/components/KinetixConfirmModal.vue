<script setup lang="ts">
import { AlertTriangle, X } from "@lucide/vue";
import { onBeforeUnmount, watch, ref, onMounted } from "vue";
import { useI18n } from "vue-i18n";
import {
  statusButtonClass,
  statusSoftClass,
} from "@/composables/useStatusColor";

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

const isMounted = ref(false);
onMounted(() => {
  isMounted.value = true;
});

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

const getConfirmButtonClass = (color?: string | null) =>
  statusButtonClass(color);

const getIconWrapperClass = (color?: string | null) => statusSoftClass(color);
</script>

<template>
  <Teleport v-if="isMounted" to="body">
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
                class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] border bg-background shadow-xs hover:bg-accent hover:text-accent-foreground dark:bg-input/30 dark:border-input dark:hover:bg-input/50 h-9 px-4 py-2 has-[>svg]:px-3 text-foreground"
                @click="cancel"
              >
                {{ cancelLabel ?? t("kinetix.cancel") }}
              </button>
              <button
                type="button"
                class="inline-flex items-center justify-center gap-2 whitespace-nowrap rounded-md text-sm font-medium transition-all disabled:pointer-events-none disabled:opacity-50 [&_svg]:pointer-events-none [&_svg:not([class*='size-'])]:size-4 shrink-0 [&_svg]:shrink-0 outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] h-9 px-4 py-2 has-[>svg]:px-3"
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
