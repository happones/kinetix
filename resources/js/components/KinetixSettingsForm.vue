<script setup lang="ts">
import { useI18n } from "vue-i18n";
import { toast } from "vue-sonner";
import { useKinetixSettings } from "@/composables/useKinetixSettings";
import { buttonVariants } from "@/composables/useShadcnVariants";
import type { KinetixSettingsPageData } from "@/types";
import KinetixForm from "./KinetixForm.vue";

/**
 * Renders a single Kinetix settings page: its schema-driven form (reusing
 * <KinetixForm>) wired to the settings endpoint. Pass the `page` prop the
 * SettingsController provides (`{ key, title, icon, form }`). Gate the screen
 * behind the `settings.manage` ability where you mount it.
 */
const props = defineProps<{
  page: KinetixSettingsPageData;
}>();

const { t } = useI18n();
const { saving, save } = useKinetixSettings();

async function onSubmit(values: Record<string, unknown>): Promise<void> {
  try {
    await save(props.page.key, values);
    toast.success(t("kinetix.settings_saved"));
  } catch (error) {
    toast.error(
      error instanceof Error ? error.message : t("kinetix.save_failed"),
    );
  }
}
</script>

<template>
  <div class="space-y-4">
    <h2 class="text-lg font-semibold text-foreground">{{ page.title }}</h2>

    <KinetixForm :form="page.form" @submit="onSubmit">
      <template #default>
        <button type="submit" :disabled="saving" :class="buttonVariants()">
          {{ t("kinetix.save") }}
        </button>
      </template>
    </KinetixForm>
  </div>
</template>
