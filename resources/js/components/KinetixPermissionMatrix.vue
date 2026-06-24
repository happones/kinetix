<script setup lang="ts">
import { computed, ref } from "vue";
import { useI18n } from "vue-i18n";
import { inputClass } from "@/composables/useShadcnVariants";
import type { KinetixPermissionFeature } from "@/types";
import KinetixCheckbox from "./KinetixCheckbox.vue";

/**
 * Feature-grouped permission grid (AWS-IAM style). `modelValue` is the selected
 * `{feature}.{ability}` keys; each feature card has a select-all toggle and the
 * search filters by feature / ability / permission key.
 */
const props = defineProps<{
  features: KinetixPermissionFeature[];
  modelValue: string[];
}>();

const emit = defineEmits<{
  (e: "update:modelValue", value: string[]): void;
}>();

const { t } = useI18n();
const search = ref("");

const filtered = computed(() => {
  const q = search.value.trim().toLowerCase();

  if (!q) {
    return props.features;
  }

  return props.features
    .map((feature) => ({
      ...feature,
      abilities: feature.abilities.filter(
        (ability) =>
          feature.label.toLowerCase().includes(q) ||
          ability.label.toLowerCase().includes(q) ||
          ability.permission.toLowerCase().includes(q),
      ),
    }))
    .filter((feature) => feature.abilities.length > 0);
});

const isSelected = (permission: string): boolean =>
  props.modelValue.includes(permission);

function toggle(permission: string): void {
  const next = isSelected(permission)
    ? props.modelValue.filter((p) => p !== permission)
    : [...props.modelValue, permission];

  emit("update:modelValue", next);
}

function featureSelectedAll(feature: KinetixPermissionFeature): boolean {
  const keys = feature.abilities.map((a) => a.permission);

  return keys.length > 0 && keys.every((k) => isSelected(k));
}

function toggleFeature(feature: KinetixPermissionFeature): void {
  const keys = feature.abilities.map((a) => a.permission);
  const all = featureSelectedAll(feature);

  const next = props.modelValue.filter((p) => !keys.includes(p));
  emit("update:modelValue", all ? next : [...next, ...keys]);
}
</script>

<template>
  <div class="space-y-4">
    <input
      v-model="search"
      type="text"
      :class="inputClass"
      :placeholder="t('kinetix.search_permissions')"
    />

    <div class="grid gap-4 sm:grid-cols-2">
      <div
        v-for="feature in filtered"
        :key="feature.name"
        class="rounded-lg border border-border bg-card p-4"
      >
        <div
          class="flex items-center justify-between gap-2 border-b border-border pb-2"
        >
          <span class="text-sm font-semibold text-foreground">{{
            feature.label
          }}</span>
          <label
            class="flex cursor-pointer items-center gap-2 text-xs text-muted-foreground"
          >
            <KinetixCheckbox
              :model-value="featureSelectedAll(feature)"
              @update:model-value="toggleFeature(feature)"
            />
            {{ t("kinetix.select_all") }}
          </label>
        </div>

        <div class="mt-3 space-y-2">
          <label
            v-for="ability in feature.abilities"
            :key="ability.permission"
            class="flex cursor-pointer items-center gap-2 text-sm text-foreground"
          >
            <KinetixCheckbox
              :model-value="isSelected(ability.permission)"
              @update:model-value="toggle(ability.permission)"
            />
            {{ ability.label }}
            <span class="ml-auto font-mono text-xs text-muted-foreground">{{
              ability.permission
            }}</span>
          </label>
        </div>
      </div>
    </div>
  </div>
</template>
