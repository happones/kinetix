<script setup lang="ts">
import { ref } from "vue";
import { router } from "@inertiajs/vue3";
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import {
  DialogContent,
  DialogOverlay,
  DialogPortal,
  DialogRoot,
  DialogTitle,
} from "reka-ui";
import { useKinetixGdpr } from "@/composables/useKinetixGdpr";
import { buttonVariants, inputClass } from "@/composables/useShadcnVariants";
import { cn } from "./primitives/cn";
import KinetixLabel from "./KinetixLabel.vue";

/**
 * Drop-in GDPR self-service panel: "download my data" (queued, delivered via a
 * notification with a download link) and "delete my account" (password-gated
 * confirmation dialog). Mount it on a privacy/account settings page.
 */
const props = withDefaults(
  defineProps<{
    /** Require the current password to confirm deletion (mirror the server config). */
    requirePassword?: boolean;
  }>(),
  { requirePassword: true },
);

const { t } = useI18n();
const { exporting, deleting, exportData, deleteAccount } = useKinetixGdpr();

const confirmOpen = ref(false);
const password = ref("");
const error = ref<string | null>(null);

async function onExport(): Promise<void> {
  try {
    await exportData();
    toast.success(t("kinetix.gdpr_export_queued"));
  } catch {
    toast.error(t("kinetix.gdpr_export_failed"));
  }
}

async function onConfirmDelete(): Promise<void> {
  error.value = null;
  try {
    const result = await deleteAccount(
      props.requirePassword ? password.value : undefined,
    );
    confirmOpen.value = false;
    router.visit(result?.redirect ?? "/");
  } catch (e) {
    error.value =
      e instanceof Error ? e.message : t("kinetix.gdpr_delete_failed");
  }
}
</script>

<template>
  <div class="space-y-6">
    <!-- Export my data -->
    <section
      class="flex flex-wrap items-start justify-between gap-4 rounded-lg border border-border bg-card p-4"
    >
      <div class="min-w-0">
        <h3 class="text-sm font-semibold text-foreground">
          {{ t("kinetix.gdpr_export_title") }}
        </h3>
        <p class="mt-1 text-sm text-muted-foreground">
          {{ t("kinetix.gdpr_export_desc") }}
        </p>
      </div>
      <button
        type="button"
        :class="buttonVariants({ variant: 'outline', size: 'sm' })"
        :disabled="exporting"
        @click="onExport"
      >
        {{ t("kinetix.gdpr_export_button") }}
      </button>
    </section>

    <!-- Delete account -->
    <section
      class="flex flex-wrap items-start justify-between gap-4 rounded-lg border border-destructive/40 bg-destructive/5 p-4"
    >
      <div class="min-w-0">
        <h3 class="text-sm font-semibold text-destructive">
          {{ t("kinetix.gdpr_delete_title") }}
        </h3>
        <p class="mt-1 text-sm text-muted-foreground">
          {{ t("kinetix.gdpr_delete_desc") }}
        </p>
      </div>
      <button
        type="button"
        :class="buttonVariants({ variant: 'destructive', size: 'sm' })"
        @click="confirmOpen = true"
      >
        {{ t("kinetix.gdpr_delete_button") }}
      </button>
    </section>

    <!-- Confirmation dialog -->
    <DialogRoot v-model:open="confirmOpen">
      <DialogPortal>
        <DialogOverlay
          class="fixed inset-0 z-50 bg-black/50 data-[state=open]:animate-in data-[state=open]:fade-in"
        />
        <DialogContent
          class="fixed top-1/2 left-1/2 z-50 w-[calc(100vw-2rem)] max-w-md -translate-x-1/2 -translate-y-1/2 rounded-lg border border-border bg-card p-6 shadow-lg"
        >
          <DialogTitle class="text-lg font-semibold text-foreground">
            {{ t("kinetix.gdpr_delete_confirm_heading") }}
          </DialogTitle>
          <p class="mt-2 text-sm text-muted-foreground">
            {{ t("kinetix.gdpr_delete_confirm_body") }}
          </p>

          <div v-if="requirePassword" class="mt-4 space-y-2">
            <KinetixLabel for="gdpr-password">{{
              t("kinetix.gdpr_password")
            }}</KinetixLabel>
            <input
              id="gdpr-password"
              v-model="password"
              type="password"
              autocomplete="current-password"
              :class="inputClass"
            />
          </div>

          <p v-if="error" class="mt-2 text-xs font-semibold text-destructive">
            {{ error }}
          </p>

          <div class="mt-6 flex justify-end gap-2">
            <button
              type="button"
              :class="buttonVariants({ variant: 'outline', size: 'sm' })"
              @click="confirmOpen = false"
            >
              {{ t("kinetix.cancel") }}
            </button>
            <button
              type="button"
              :class="cn(buttonVariants({ variant: 'destructive', size: 'sm' }))"
              :disabled="deleting || (requirePassword && !password)"
              @click="onConfirmDelete"
            >
              {{ t("kinetix.gdpr_delete_button") }}
            </button>
          </div>
        </DialogContent>
      </DialogPortal>
    </DialogRoot>
  </div>
</template>
