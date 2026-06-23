<script setup lang="ts">
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import KinetixPlanCard from "@/components/KinetixPlanCard.vue";
import type { KinetixPlanData } from "@/types";

const { t } = useI18n();

const props = withDefaults(
  defineProps<{
    plans?: KinetixPlanData[];
    currentPlanSlug?: string | null;
    selectedSlug?: string | null;
    loading?: boolean;
    cycle?: "monthly" | "yearly";
    /** Show the monthly/yearly toggle (only when plans expose yearly prices). */
    showCycleToggle?: boolean;
    currencySymbol?: string;
    featureLabels?: Record<string, string>;
  }>(),
  {
    plans: () => [],
    currentPlanSlug: null,
    selectedSlug: null,
    loading: false,
    cycle: "monthly",
    showCycleToggle: false,
    currencySymbol: "$",
    featureLabels: () => ({}),
  },
);

const emit = defineEmits<{
  (e: "subscribe", plan: KinetixPlanData): void;
  (e: "update:cycle", cycle: "monthly" | "yearly"): void;
}>();

const hasYearly = computed(() =>
  props.plans.some((plan) => plan.yearlyPrice != null),
);

const columns = computed(() => {
  const count = Math.min(props.plans.length, 4);

  return (
    {
      1: "sm:grid-cols-1",
      2: "sm:grid-cols-2",
      3: "sm:grid-cols-2 lg:grid-cols-3",
      4: "sm:grid-cols-2 lg:grid-cols-4",
    }[count] ?? "sm:grid-cols-3"
  );
});
</script>

<template>
  <div class="space-y-6">
    <div
      v-if="showCycleToggle && hasYearly"
      class="flex items-center justify-center"
    >
      <div class="inline-flex rounded-lg border border-border bg-muted p-1">
        <button
          type="button"
          class="rounded-md px-4 py-1.5 text-sm font-medium transition-colors"
          :class="
            cycle === 'monthly'
              ? 'bg-background text-foreground shadow-sm'
              : 'text-muted-foreground hover:text-foreground'
          "
          @click="emit('update:cycle', 'monthly')"
        >
          {{ t("kinetix.billing_monthly") }}
        </button>
        <button
          type="button"
          class="rounded-md px-4 py-1.5 text-sm font-medium transition-colors"
          :class="
            cycle === 'yearly'
              ? 'bg-background text-foreground shadow-sm'
              : 'text-muted-foreground hover:text-foreground'
          "
          @click="emit('update:cycle', 'yearly')"
        >
          {{ t("kinetix.billing_yearly") }}
        </button>
      </div>
    </div>

    <div class="grid grid-cols-1 gap-6" :class="columns">
      <KinetixPlanCard
        v-for="plan in plans"
        :key="plan.slug"
        :plan="plan"
        :cycle="cycle"
        :currency-symbol="currencySymbol"
        :feature-labels="featureLabels"
        :is-active="plan.slug === currentPlanSlug"
        :is-selected="plan.slug === selectedSlug"
        :loading="loading"
        :can-subscribe="!loading"
        @subscribe="emit('subscribe', $event)"
      />
    </div>
  </div>
</template>
