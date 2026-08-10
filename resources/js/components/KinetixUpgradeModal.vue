<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Sparkles } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixPlan } from '@/composables/useKinetixPlan';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import KinetixButton from './KinetixButton.vue';
import KinetixModal from './primitives/KinetixModal.vue';

/**
 * The upsell dialog every `<KinetixPlanLock>` opens when its CTA is pressed
 * (`modal`), and a standalone component when the app wants to trigger the same
 * upsell from its own code:
 *
 *     <KinetixUpgradeModal v-model:open="open" feature-name="Discord alerts" />
 *
 * The CTA points at `kinetix.billing.upgrade_url` unless `upgradeUrl` overrides
 * it; with neither, only the dismiss button renders (no dead link).
 */
const props = withDefaults(
    defineProps<{
        open: boolean;
        /** Human name of the locked feature, woven into the body copy. */
        featureName?: string | null;
        title?: string | null;
        description?: string | null;
        ctaLabel?: string | null;
        /** Overrides `kinetix.billing.upgrade_url` for this dialog. */
        upgradeUrl?: string | null;
    }>(),
    {
        featureName: null,
        title: null,
        description: null,
        ctaLabel: null,
        upgradeUrl: undefined,
    },
);

const emit = defineEmits<{ (e: 'update:open', value: boolean): void }>();

const { t } = useI18n();
const { upgradeUrl: configuredUpgradeUrl } = useKinetixPlan();

const href = computed(() =>
    props.upgradeUrl === undefined
        ? configuredUpgradeUrl.value
        : props.upgradeUrl,
);

const body = computed(() => {
    if (props.description) {
        return props.description;
    }

    return props.featureName
        ? t('kinetix.plan_locked_feature', { feature: props.featureName })
        : t('kinetix.plan_upgrade_modal_body');
});
</script>

<template>
    <KinetixModal
        :open="open"
        max-width="sm:max-w-md"
        @update:open="emit('update:open', $event)"
    >
        <template #header="{ headingId }">
            <div class="gap-3 flex flex-col items-center text-center">
                <div
                    class="size-11 flex items-center justify-center rounded-full bg-primary/10 text-primary"
                >
                    <Sparkles class="size-5" aria-hidden="true" />
                </div>
                <h2
                    :id="headingId"
                    class="text-lg font-semibold tracking-tight leading-none"
                >
                    {{ title ?? t('kinetix.plan_upgrade_modal_title') }}
                </h2>
                <p class="text-sm max-w-sm text-muted-foreground">
                    {{ body }}
                </p>
            </div>
        </template>

        <slot />

        <template #footer>
            <KinetixButton
                variant="ghost"
                class="sm:w-auto w-full"
                @click="emit('update:open', false)"
            >
                {{ t('kinetix.plan_upgrade_dismiss') }}
            </KinetixButton>
            <Link
                v-if="href"
                :href="href"
                :class="buttonVariants()"
                class="sm:w-auto w-full"
                @click="emit('update:open', false)"
            >
                {{ ctaLabel ?? t('kinetix.plan_upgrade') }}
            </Link>
        </template>
    </KinetixModal>
</template>
