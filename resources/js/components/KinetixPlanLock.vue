<script setup lang="ts">
import { Lock } from '@lucide/vue';
import { computed, ref, useSlots } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    useKinetixPlan,
    useKinetixPlanAccess,
} from '@/composables/useKinetixPlan';
import { statusTextClass } from '@/composables/useKinetixStatusColor';
import type { KinetixPlanLockVariant } from '@/types/kinetix';
import PlanLockCta from './Billing/PlanLockCta.vue';
import PlanLockPanel from './Billing/PlanLockPanel.vue';
import KinetixUpgradeModal from './KinetixUpgradeModal.vue';
import KinetixBadge from './primitives/KinetixBadge.vue';

/**
 * The padlock: one component for every way a plan-locked feature is presented,
 * so an app can gate a whole page, a panel, a menu item or a tab with the same
 * dot-paths the server enforces. Four presentations (`variant`):
 *
 * - `card` — dashed lock card replacing the content (whole locked modules).
 * - `overlay` — the content stays visible but blurred, inert and covered by
 *   the lock (settings panels, dashboards: "here's what you're missing").
 * - `banner` — a row-shaped upsell strip. Usually standalone (no default
 *   slot), sitting above content the plan only partly unlocks.
 * - `badge` — the content renders dimmed with a padlock appended, and any
 *   click opens the upgrade modal (sidebar items, tab triggers).
 *
 * ```vue
 * <KinetixPlanLock feature="capabilities.api">
 *     <ApiTokensPanel />
 * </KinetixPlanLock>
 *
 * <KinetixPlanLock variant="overlay" feature="alerts.discord" feature-name="Discord alerts">
 *     <DiscordSettings />
 * </KinetixPlanLock>
 *
 * <KinetixPlanLock variant="badge" feature="capabilities.api">
 *     <SidebarItem>API tokens</SidebarItem>
 * </KinetixPlanLock>
 *
 * <!-- No feature/limit prop = an unconditional upsell -->
 * <KinetixPlanLock variant="banner" feature-name="Real-time alerts" />
 * ```
 *
 * Defaults for `variant`/`modal`/`blur`/`badgeLabel` come from
 * `kinetix.billing.lock`; per-instance props always win. Provide `#locked` to
 * replace the lock UI entirely. Fail-closed like every plan check: no plan (or
 * billing off) = locked. Display gating only — the server must still enforce
 * the feature (`kinetix.plan:` middleware / `HasPlan` / `EnforcesPlanLimits`).
 */
const props = withDefaults(
    defineProps<{
        /** Dot-path of a capability flag, e.g. `capabilities.api`. */
        feature?: string;
        /** Dot-path of a usage limit, e.g. `usage.projects`. */
        limit?: string;
        /** Current usage count, compared against the `limit` path. */
        count?: number;
        /** Presentation; defaults to `kinetix.billing.lock.variant`. */
        variant?: KinetixPlanLockVariant;
        /** Human name of the feature, woven into the default copy. */
        featureName?: string | null;
        title?: string | null;
        description?: string | null;
        ctaLabel?: string | null;
        /** Overrides `kinetix.billing.upgrade_url` for this lock. */
        upgradeUrl?: string | null;
        /** CTA opens the upgrade modal instead of navigating. */
        modal?: boolean;
        /** `overlay`: blur the content behind the lock. */
        blur?: boolean;
        /** Plan pill next to the title (e.g. 'Pro'). */
        badgeLabel?: string | null;
    }>(),
    {
        feature: undefined,
        limit: undefined,
        count: 0,
        variant: undefined,
        featureName: null,
        title: null,
        description: null,
        ctaLabel: null,
        upgradeUrl: undefined,
        modal: undefined,
        blur: undefined,
        badgeLabel: undefined,
    },
);

const emit = defineEmits<{ (e: 'upgrade'): void }>();

const { t } = useI18n();
const slots = useSlots();
const { upgradeUrl: configuredUpgradeUrl, lockDefaults } = useKinetixPlan();
const { gated, allowed, remainingCount } = useKinetixPlanAccess(() => props);

/** Without a gating prop the lock is an unconditional upsell. */
const locked = computed(() => (gated.value ? !allowed.value : true));

const variant = computed(() => props.variant ?? lockDefaults.value.variant);
const useModal = computed(() => props.modal ?? lockDefaults.value.modal);
const blurred = computed(() => props.blur ?? lockDefaults.value.blur);
const badgeLabel = computed(
    () => props.badgeLabel ?? lockDefaults.value.badgeLabel,
);

const href = computed(() =>
    props.upgradeUrl === undefined
        ? configuredUpgradeUrl.value
        : props.upgradeUrl,
);

const title = computed(() => props.title ?? t('kinetix.plan_locked_title'));

const description = computed(() => {
    if (props.description) {
        return props.description;
    }

    return props.featureName
        ? t('kinetix.plan_locked_feature', { feature: props.featureName })
        : t('kinetix.plan_locked_body');
});

const ctaLabel = computed(() => props.ctaLabel ?? t('kinetix.plan_upgrade'));

const modalOpen = ref(false);

const openUpgrade = () => {
    emit('upgrade');

    if (useModal.value) {
        modalOpen.value = true;
    }
};

/**
 * `badge`: swallow the click on the wrapped control (a link would navigate to
 * a page the plan doesn't include) and sell the upgrade instead.
 */
const onBadgeClick = (event: MouseEvent) => {
    if (!useModal.value && href.value) {
        return;
    }

    event.preventDefault();
    event.stopPropagation();

    openUpgrade();
};
</script>

<template>
    <slot v-if="!locked" :remaining="remainingCount" />

    <slot v-else name="locked" :remaining="remainingCount" :open="openUpgrade">
        <!-- badge: the content stays, dimmed, with a padlock appended. -->
        <span
            v-if="variant === 'badge'"
            class="gap-1.5 inline-flex items-center"
            @click.capture="onBadgeClick"
        >
            <span v-if="slots.default" class="opacity-60">
                <slot :remaining="remainingCount" />
            </span>
            <Lock
                class="size-3.5 shrink-0"
                :class="statusTextClass('warning')"
                aria-hidden="true"
            />
            <span class="sr-only">{{ t('kinetix.plan_locked_hint') }}</span>
        </span>

        <!-- overlay: content visible but inert behind the lock. -->
        <div v-else-if="variant === 'overlay'" class="relative">
            <div
                class="pointer-events-none opacity-40 select-none"
                :class="blurred ? 'blur-[2px]' : ''"
                aria-hidden="true"
                inert
            >
                <slot :remaining="remainingCount" />
            </div>
            <div
                class="inset-0 p-4 rounded-lg absolute flex items-center justify-center bg-background/70 backdrop-blur-[1px]"
            >
                <PlanLockPanel
                    :title="title"
                    :description="description"
                    :badge-label="badgeLabel"
                    :cta-label="ctaLabel"
                    :href="href"
                    :modal="useModal"
                    @upgrade="openUpgrade"
                />
            </div>
        </div>

        <!-- banner: row-shaped upsell strip. -->
        <div
            v-else-if="variant === 'banner'"
            class="p-4 gap-4 rounded-lg sm:flex-row sm:items-center flex flex-col border border-border bg-card"
        >
            <div
                class="size-10 rounded-lg flex shrink-0 items-center justify-center bg-primary/10 text-primary"
            >
                <Lock class="size-5" aria-hidden="true" />
            </div>
            <div class="space-y-1 flex-1">
                <div class="gap-2 flex items-center">
                    <h3 class="text-sm font-semibold text-foreground">
                        {{ title }}
                    </h3>
                    <KinetixBadge v-if="badgeLabel" variant="outline" size="sm">
                        {{ badgeLabel }}
                    </KinetixBadge>
                </div>
                <p class="text-sm text-muted-foreground">{{ description }}</p>
            </div>
            <PlanLockCta
                class="shrink-0"
                :href="href"
                :label="ctaLabel"
                :modal="useModal"
                @upgrade="openUpgrade"
            />
        </div>

        <!-- card (default): the locked-module placeholder. -->
        <div
            v-else
            class="rounded-lg px-6 py-12 border border-dashed border-border"
        >
            <PlanLockPanel
                :title="title"
                :description="description"
                :badge-label="badgeLabel"
                :cta-label="ctaLabel"
                :href="href"
                :modal="useModal"
                @upgrade="openUpgrade"
            />
        </div>
    </slot>

    <KinetixUpgradeModal
        v-if="useModal"
        v-model:open="modalOpen"
        :feature-name="featureName"
        :description="props.description"
        :cta-label="props.ctaLabel"
        :upgrade-url="upgradeUrl"
    />
</template>
