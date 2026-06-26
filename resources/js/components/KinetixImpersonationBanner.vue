<script setup lang="ts">
import { useI18n } from "vue-i18n";
import { useKinetixImpersonation } from "@/composables/useKinetixImpersonation";
import { buttonVariants } from "@/composables/useShadcnVariants";

/**
 * Top banner shown while impersonating, with a "return to your account" button.
 * Mount it once in your authenticated layout — it renders nothing unless an
 * impersonation session is active (read from the `kinetix_impersonation` prop).
 */
const { t } = useI18n();
const { active, impersonatedName, leave } = useKinetixImpersonation();
</script>

<template>
  <div
    v-if="active"
    class="flex items-center justify-center gap-3 bg-warning px-4 py-2 text-sm text-warning-foreground"
  >
    <span>{{
      t("kinetix.impersonating", { name: impersonatedName ?? "" })
    }}</span>
    <button
      type="button"
      :class="[buttonVariants({ variant: 'outline', size: 'sm' }), 'text-foreground']"
      @click="leave"
    >
      {{ t("kinetix.impersonation_leave") }}
    </button>
  </div>
</template>
