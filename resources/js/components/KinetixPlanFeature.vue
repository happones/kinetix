<script setup lang="ts">
import { computed } from 'vue';
import { useKinetixPlan } from '@/composables/useKinetixPlan';

/**
 * Declarative plan gate — the billing twin of `<KinetixCan>`. Two modes:
 *
 * Capability (boolean feature on the current plan):
 *
 *     <KinetixPlanFeature feature="capabilities.api">
 *         <ApiMenuItem />
 *         <template #denied><UpgradeHint /></template>
 *     </KinetixPlanFeature>
 *
 * Usage limit (renders the default slot while UNDER the limit; `remaining`
 * is exposed to both slots — null means unlimited):
 *
 *     <KinetixPlanFeature limit="usage.products" :count="products.length">
 *         <template #default="{ remaining }">
 *             <AddProductButton /> <span v-if="remaining !== null">{{ remaining }} left</span>
 *         </template>
 *         <template #denied><UpgradeToAddMore /></template>
 *     </KinetixPlanFeature>
 *
 * Both props together require both checks to pass. Display gating only — the
 * server must still enforce the feature (`plan.feature` middleware / HasPlan).
 */
const props = withDefaults(
    defineProps<{
        /** Dot-path of a capability flag, e.g. `capabilities.api`. */
        feature?: string;
        /** Dot-path of a usage limit, e.g. `usage.products`. */
        limit?: string;
        /** Current usage count, compared against the `limit` path. */
        count?: number;
    }>(),
    { feature: undefined, limit: undefined, count: 0 },
);

const { canUseFeature, hasReachedLimit, remaining } = useKinetixPlan();

const allowed = computed(() => {
    let ok = true;

    if (props.feature !== undefined) {
        ok = ok && canUseFeature(props.feature);
    }

    if (props.limit !== undefined) {
        ok = ok && !hasReachedLimit(props.limit, props.count);
    }

    return ok;
});

/** Units left on the `limit` path (null = unlimited or no limit prop). */
const remainingCount = computed(() =>
    props.limit !== undefined ? remaining(props.limit, props.count) : null,
);
</script>

<template>
    <slot v-if="allowed" :remaining="remainingCount" />
    <slot v-else name="denied" :remaining="remainingCount" />
</template>
