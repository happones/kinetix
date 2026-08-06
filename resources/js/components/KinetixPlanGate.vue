<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Lock } from '@lucide/vue';
import { useI18n } from 'vue-i18n';
import { useKinetixPlan } from '@/composables/useKinetixPlan';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import KinetixPlanFeature from './KinetixPlanFeature.vue';

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

const { t } = useI18n();
const { upgradeUrl } = useKinetixPlan();
</script>

<template>
    <KinetixPlanFeature :feature="feature" :limit="limit" :count="count">
        <template #default="{ remaining }">
            <slot :remaining="remaining" />
        </template>
        <template #denied="{ remaining }">
            <slot name="locked" :remaining="remaining">
                <div
                    class="rounded-lg px-6 py-12 flex flex-col items-center justify-center border border-dashed border-border text-center"
                >
                    <div
                        class="mb-3 size-10 flex items-center justify-center rounded-full bg-muted"
                    >
                        <Lock
                            class="size-5 text-muted-foreground"
                            aria-hidden="true"
                        />
                    </div>
                    <h3 class="text-sm font-semibold text-foreground">
                        {{ t('kinetix.plan_locked_title') }}
                    </h3>
                    <p class="mt-1 text-sm max-w-sm text-muted-foreground">
                        {{ t('kinetix.plan_locked_body') }}
                    </p>
                    <Link
                        v-if="upgradeUrl"
                        :href="upgradeUrl"
                        :class="buttonVariants({ size: 'sm' })"
                        class="mt-4"
                    >
                        {{ t('kinetix.plan_upgrade') }}
                    </Link>
                </div>
            </slot>
        </template>
    </KinetixPlanFeature>
</template>
