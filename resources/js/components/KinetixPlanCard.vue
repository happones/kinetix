<script setup lang="ts">
import { Check, Loader2, X } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import type { KinetixPlanData } from '@/types/kinetix';
import KinetixConfirmModal from './KinetixConfirmModal.vue';
import Card from './primitives/Card.vue';
import CardContent from './primitives/CardContent.vue';
import CardDescription from './primitives/CardDescription.vue';
import CardFooter from './primitives/CardFooter.vue';
import CardHeader from './primitives/CardHeader.vue';
import CardTitle from './primitives/CardTitle.vue';
import { cn } from './primitives/cn';
import KinetixBadge from './primitives/KinetixBadge.vue';

const props = withDefaults(
    defineProps<{
        plan: KinetixPlanData;
        isActive?: boolean;
        isSelected?: boolean;
        loading?: boolean;
        canSubscribe?: boolean;
        /** The id of what says why it can't be chosen now (aria-describedby). */
        disabledReasonId?: string | null;
        /** 'monthly' | 'yearly' — which price to display. */
        cycle?: 'monthly' | 'yearly';
        /** Whether to show the plan's trial badge. */
        showTrial?: boolean;
        currencySymbol?: string;
        /**
         * Optional dot-path → label map rendering capability rows with a check/X
         * based on the plan's feature gating. Keeps the card generic across apps.
         */
        featureLabels?: Record<string, string>;
        /**
         * Monthly price of the plan the billable is on. A dearer plan reads
         * "Upgrade" and any other "Switch plan": with no reference every paid
         * plan said "Upgrade", a downgrade included.
         */
        currentPrice?: number | null;
    }>(),
    {
        isActive: false,
        isSelected: false,
        loading: false,
        canSubscribe: true,
        disabledReasonId: null,
        cycle: 'monthly',
        currencySymbol: '$',
        showTrial: true,
        featureLabels: () => ({}),
        currentPrice: null,
    },
);

const emit = defineEmits<{
    (e: 'subscribe', plan: KinetixPlanData): void;
}>();

const { t, locale } = useI18n();

const price = computed(() =>
    props.cycle === 'yearly' ? props.plan.yearlyPrice : props.plan.monthlyPrice,
);

const periodLabel = computed(() =>
    props.cycle === 'yearly'
        ? t('kinetix.billing_per_year')
        : t('kinetix.billing_per_month'),
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
        .split('.')
        .reduce<any>(
            (acc, part) => (acc == null ? undefined : acc[part]),
            props.plan.features,
        );

    if (typeof value === 'boolean') {
        return value;
    }

    if (Array.isArray(value)) {
        return value.length > 0;
    }

    // null is treated as "unlimited" (granted); 0 / undefined as denied.
    return value === null || !!value;
}

/**
 * The price in the visitor's number format, cents kept when it has them:
 * `Math.floor()` showed a 14.99 plan as 14.
 */
const formattedPrice = computed(() => {
    const value = price.value ?? 0;

    return new Intl.NumberFormat(locale.value, {
        minimumFractionDigits: Number.isInteger(value) ? 0 : 2,
        maximumFractionDigits: 2,
    }).format(value);
});

const ctaLabel = computed(() =>
    (props.plan.monthlyPrice ?? 0) > (props.currentPrice ?? 0)
        ? t('kinetix.billing_upgrade')
        : t('kinetix.billing_switch_plan'),
);

/**
 * Moving to a free plan cancels the paid subscription at once, so it asks
 * first. A free plan that is not the active one only shows to a paying
 * billable; every other choice goes through checkout and needs no extra step.
 */
const confirmingFree = ref(false);

function choose(): void {
    if (props.plan.isFree) {
        confirmingFree.value = true;

        return;
    }

    emit('subscribe', props.plan);
}

function confirmFree(): void {
    confirmingFree.value = false;
    emit('subscribe', props.plan);
}
</script>

<template>
    <Card
        :class="
            cn(
                'transition-all',
                isActive ? 'border-primary ring-2 ring-primary' : '',
                isSelected && !isActive
                    ? 'border-primary ring-2 ring-primary/40'
                    : '',
            )
        "
    >
        <CardHeader>
            <CardTitle class="flex items-center justify-between">
                <span class="text-lg tracking-tight">{{ plan.name }}</span>
                <span
                    v-if="isActive"
                    class="px-2 py-0.5 font-medium inline-flex w-fit items-center rounded-full border border-transparent bg-primary text-[10px] text-primary-foreground uppercase"
                >
                    {{ t('kinetix.billing_current_plan') }}
                </span>
                <span
                    v-else-if="plan.isFeatured"
                    class="px-2 py-0.5 font-medium inline-flex w-fit items-center rounded-full border border-transparent bg-secondary text-[10px] text-secondary-foreground uppercase"
                >
                    {{ t('kinetix.billing_featured') }}
                </span>
            </CardTitle>
            <CardDescription v-if="plan.description">{{
                plan.description
            }}</CardDescription>
        </CardHeader>

        <!-- flex-1: the content takes the spare height, so every card's price
             sits right under its header and the buttons line up at the bottom
             (spreading the space between the three pushed the prices to
             different heights). -->
        <CardContent class="flex-1">
            <div class="text-3xl font-bold">
                {{ currencySymbol }}{{ formattedPrice }}
                <span class="text-sm font-normal text-muted-foreground">{{
                    periodLabel
                }}</span>
            </div>

            <KinetixBadge
                v-if="showTrial && plan.trialDays && plan.trialDays > 0"
                color="warning"
                size="sm"
                class="mt-2"
            >
                {{ t('kinetix.billing_trial_badge', { days: plan.trialDays }) }}
            </KinetixBadge>

            <ul class="mt-6 space-y-2.5 text-sm">
                <li
                    v-for="feature in plan.highlightedFeatures"
                    :key="feature"
                    class="gap-2 flex items-start"
                >
                    <Check class="mt-0.5 h-4 w-4 shrink-0 text-primary" />
                    <span class="font-medium text-foreground">{{
                        feature
                    }}</span>
                </li>

                <li
                    v-if="
                        capabilities.length && plan.highlightedFeatures.length
                    "
                    class="my-3 border-t border-border/60"
                ></li>

                <li
                    v-for="cap in capabilities"
                    :key="cap.path"
                    class="gap-2 flex items-start"
                >
                    <Check
                        v-if="cap.granted"
                        class="mt-0.5 h-4 w-4 shrink-0 text-primary"
                    />
                    <X
                        v-else
                        class="mt-0.5 h-4 w-4 shrink-0 text-muted-foreground/60"
                    />
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
                class="px-4 py-2 text-sm font-medium w-full rounded-md border border-primary/20 bg-primary/10 text-center text-primary"
            >
                {{ t('kinetix.billing_current_plan') }}
            </div>
            <button
                v-else
                type="button"
                :class="cn(buttonVariants(), 'w-full')"
                :disabled="loading || !canSubscribe"
                :aria-describedby="disabledReasonId ?? undefined"
                @click="choose"
            >
                <Loader2
                    v-if="loading && isSelected"
                    class="mr-2 h-4 w-4 animate-spin"
                />
                {{ ctaLabel }}
            </button>
        </CardFooter>

        <KinetixConfirmModal
            :open="confirmingFree"
            color="warning"
            :heading="
                t('kinetix.billing_switch_free_confirm_title', {
                    plan: plan.name,
                })
            "
            :description="
                t('kinetix.billing_switch_free_confirm_description', {
                    plan: plan.name,
                })
            "
            :submit-label="t('kinetix.billing_switch_plan')"
            :cancel-label="t('kinetix.billing_keep_subscription')"
            @update:open="confirmingFree = $event"
            @confirm="confirmFree"
        />
    </Card>
</template>
