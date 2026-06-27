<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import KinetixCombobox from "./KinetixCombobox.vue";
import KinetixLabel from "./KinetixLabel.vue";
import { inputClass } from "@/composables/useShadcnVariants";

type Address = {
  line1?: string | null;
  line2?: string | null;
  city?: string | null;
  state?: string | null;
  postalCode?: string | null;
  country?: string | null;
} | null;

/**
 * Structured address field: text inputs for each part plus a searchable country
 * select. Value is `{ line1, line2, city, state, postalCode, country }`.
 */
const props = withDefaults(
  defineProps<{
    value?: Address;
    fields?: string[];
    /** Country options (code => label). */
    countries?: Record<string, string>;
    disabled?: boolean;
  }>(),
  {
    value: null,
    fields: () => ["line1", "line2", "city", "state", "postalCode", "country"],
    countries: () => ({}),
    disabled: false,
  },
);

const emit = defineEmits<{ (e: "update:value", value: Address): void }>();

const { t } = useI18n();

const labels: Record<string, string> = {
  line1: "address_line1",
  line2: "address_line2",
  city: "address_city",
  state: "address_state",
  postalCode: "address_postal",
  country: "address_country",
};

// line1/line2 span the full grid; the rest sit two-per-row.
const fullWidth = (field: string) => field === "line1" || field === "line2";

const set = (field: string, value: string | null) => {
  emit("update:value", { ...(props.value ?? {}), [field]: value || null });
};

const val = (field: string): string =>
  ((props.value ?? {}) as Record<string, string | null>)[field] ?? "";

const id = (field: string) => `kx-addr-${field}`;
const orderedFields = computed(() => props.fields);
</script>

<template>
  <div class="grid grid-cols-2 gap-3">
    <div
      v-for="field in orderedFields"
      :key="field"
      class="space-y-1.5"
      :class="fullWidth(field) ? 'col-span-2' : 'col-span-2 sm:col-span-1'"
    >
      <KinetixLabel :for="id(field)">{{ t(`kinetix.${labels[field]}`) }}</KinetixLabel>

      <KinetixCombobox
        v-if="field === 'country'"
        :id="id(field)"
        :value="val('country')"
        :options="countries"
        :disabled="disabled"
        :placeholder="t('kinetix.address_country')"
        @update:value="(v) => set('country', v)"
      />
      <input
        v-else
        :id="id(field)"
        :value="val(field)"
        type="text"
        :disabled="disabled"
        :class="inputClass"
        @input="set(field, ($event.target as HTMLInputElement).value)"
      />
    </div>
  </div>
</template>
