<script setup lang="ts">
import { Check, Loader2, X } from "@lucide/vue";
import { computed } from "vue";
import { useI18n } from "vue-i18n";
import type { KinetixPlanData } from "@/types";
import Card from "./primitives/Card.vue";
import CardHeader from "./primitives/CardHeader.vue";
import CardTitle from "./primitives/CardTitle.vue";
import CardDescription from "./primitives/CardDescription.vue";
import CardContent from "./primitives/CardContent.vue";
import CardFooter from "./primitives/CardFooter.vue";
import { cn } from "./primitives/cn";
import { buttonVariants } from "@/composables/useShadcnVariants";

const props = withDefaults(
  defineProps<{
    plan: KinetixPlanData;
    isActive?: boolean;
    isSelected?: boolean;
    loading?: boolean;
    canSubscribe?: boolean;
    /** 'monthly' | 'yearly' — which price to display. */
    cycle?: "monthly" | "yearly";
    currencySymbol?: string;
    /**
     * Optional dot-path → label map rendering capability rows with a check/X
     * based on the plan's feature gating. Keeps the card generic across apps.
     */
    featureLabels?: Record<string, string>;
  }>(),
  {
    isActive: false,
    isSelected: false,
    loading: false,
    canSubscribe: true,
    cycle: "monthly",
    currencySymbol: "$",
    featureLabels: () => ({}),
  },
);

const emit = defineEmits<{
  (e: "subscribe", plan: KinetixPlanData): void;
}>();

const { t } = useI18n();

const price = computed(() =>
  props.cycle === "yearly" ? props.plan.yearlyPrice : props.plan.monthlyPrice,
);

const periodLabel = computed(() =>
  props.cycle === "yearly"
    ? t("kinetix.billing_per_year")
    : t("kinetix.billing_per_month"),
);

const capabilities = computed(() =>
  Object.entries(props.featureLabels).map(([path, label]) => ({
    path,
    label,
    granted: hasFeature(path),
  })),
);

function hasFeature(path: string): boolean {
  const value = path
    .split(".")
    .reduce<any>((acc, part) => (acc == null ? undefined : acc[part]), props.plan.features);

  if (typeof value === "boolean") {
    return value;
  }

  if (Array.isArray(value)) {
    return value.length > 0;
  }

  // null is treated as "unlimited" (granted); 0 / undefined as denied.
  return value === null || !!value;
}

const ctaLabel = computed(() =>
  (props.plan.monthlyPrice ?? 0) > 0
    ? t("kinetix.billing_upgrade")
    : t("kinetix.billing_switch_plan"),
);
</script>

<template>
  <Card
    :class="
      cn(
        'justify-between transition-all',
        isActive ? 'border-primary ring-2 ring-primary' : '',
        isSelected && !isActive ? 'border-primary ring-2 ring-primary/40' : '',
      )
    "
  >
    <CardHeader>
      <CardTitle class="flex items-center justify-between">
        <span class="text-lg tracking-tight">{{ plan.name }}</span>
        <span
          v-if="isActive"
          class="inline-flex w-fit items-center rounded-full border border-transparent bg-primary px-2 py-0.5 text-[10px] font-medium uppercase text-primary-foreground"
        >
          {{ t("kinetix.billing_current_plan") }}
        </span>
        <span
          v-else-if="plan.isFeatured"
          class="inline-flex w-fit items-center rounded-full border border-transparent bg-secondary px-2 py-0.5 text-[10px] font-medium uppercase text-secondary-foreground"
        >
          ★
        </span>
      </CardTitle>
      <CardDescription v-if="plan.description">{{ plan.description }}</CardDescription>
    </CardHeader>

    <CardContent>
      <div class="text-3xl font-bold">
        {{ currencySymbol }}{{ Math.floor(price ?? 0) }}
        <span class="text-sm font-normal text-muted-foreground">{{ periodLabel }}</span>
      </div>

      <ul class="mt-6 space-y-2.5 text-sm">
        <li
          v-for="feature in plan.highlightedFeatures"
          :key="feature"
          class="flex items-start gap-2"
        >
          <Check class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
          <span class="font-medium text-foreground">{{ feature }}</span>
        </li>

        <li
          v-if="capabilities.length && plan.highlightedFeatures.length"
          class="my-3 border-t border-border/60"
        ></li>

        <li
          v-for="cap in capabilities"
          :key="cap.path"
          class="flex items-start gap-2"
        >
          <Check v-if="cap.granted" class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
          <X v-else class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground/60" />
          <span
            :class="
              cap.granted
                ? 'text-foreground'
                : 'text-muted-foreground/70 line-through decoration-muted-foreground/30'
            "
          >
            {{ cap.label }}
          </span>
        </li>
      </ul>
    </CardContent>

    <CardFooter>
      <div
        v-if="isActive"
        class="w-full rounded-md border border-primary/20 bg-primary/10 px-4 py-2 text-center text-sm font-medium text-primary"
      >
        {{ t("kinetix.billing_current_plan") }}
      </div>
      <button
        v-else
        type="button"
        :class="cn(buttonVariants(), 'w-full')"
        :disabled="loading || !canSubscribe"
        @click="emit('subscribe', plan)"
      >
        <Loader2 v-if="loading && isSelected" class="mr-2 h-4 w-4 animate-spin" />
        {{ ctaLabel }}
      </button>
    </CardFooter>
  </Card>
</template>
