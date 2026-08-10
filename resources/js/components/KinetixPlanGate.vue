<script setup lang="ts">
import KinetixPlanLock from './KinetixPlanLock.vue';

/**
 * The "module locked" upsell pattern: `<KinetixPlanFeature>` with a built-in
 * denied state — a lock card with an Upgrade CTA (shown when
 * `kinetix.billing.upgrade_url` is configured). Same props (dot-paths):
 *
 *     <KinetixPlanGate feature="capabilities.api">
 *         <ApiTokensPanel />
 *     </KinetixPlanGate>
 *
 *     <KinetixPlanGate limit="usage.projects" :count="projects.length">
 *         <NewProjectForm />
 *     </KinetixPlanGate>
 *
 * Provide a `#locked` slot to replace the card. Use `<KinetixPlanFeature>`
 * directly when the denied state should render nothing (menu items, buttons).
 * Fail-closed like every plan check: no plan (or billing off) = locked.
 * Display gating only — the server must still enforce the feature
 * (`kinetix.plan:` middleware / `HasPlan` / `EnforcesPlanLimits`).
 *
 * This is `<KinetixPlanLock variant="card">` with the CTA linking straight to
 * the upgrade URL — reach for `<KinetixPlanLock>` when you want another
 * presentation (overlay, banner, badge) or the upgrade modal.
 */
withDefaults(
    defineProps<{
        /** Dot-path of a capability flag, e.g. `capabilities.api`. */
        feature?: string;
        /** Dot-path of a usage limit, e.g. `usage.projects`. */
        limit?: string;
        /** Current usage count, compared against the `limit` path. */
        count?: number;
    }>(),
    { feature: undefined, limit: undefined, count: 0 },
);
</script>

<template>
    <KinetixPlanLock
        variant="card"
        :feature="feature"
        :limit="limit"
        :count="count"
        :modal="false"
    >
        <template #default="{ remaining }">
            <slot :remaining="remaining" />
        </template>
        <template v-if="$slots.locked" #locked="{ remaining }">
            <slot name="locked" :remaining="remaining" />
        </template>
    </KinetixPlanLock>
</template>
