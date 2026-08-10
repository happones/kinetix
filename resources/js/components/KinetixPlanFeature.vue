<script setup lang="ts">
import { useKinetixPlanAccess } from '@/composables/useKinetixPlan';

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

const { allowed, remainingCount } = useKinetixPlanAccess(() => props);
</script>

<template>
    <slot v-if="allowed" :remaining="remainingCount" />
    <slot v-else name="denied" :remaining="remainingCount" />
</template>
