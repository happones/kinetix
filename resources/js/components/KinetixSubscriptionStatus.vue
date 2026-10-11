<script setup lang="ts">
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import type { KinetixSubscriptionData } from '@/types/kinetix';
import KinetixConfirmModal from './KinetixConfirmModal.vue';
import Card from './primitives/Card.vue';
import CardContent from './primitives/CardContent.vue';
import CardFooter from './primitives/CardFooter.vue';
import CardHeader from './primitives/CardHeader.vue';
import CardTitle from './primitives/CardTitle.vue';
import { cn } from './primitives/cn';

const { t, te, locale } = useI18n();

const props = withDefaults(
    defineProps<{
        subscription?: KinetixSubscriptionData | null;
        /** The plan a billable without a subscription is on, named in place of "no subscription". */
        currentPlanName?: string | null;
    }>(),
    {
        subscription: null,
        currentPlanName: null,
    },
);

const emit = defineEmits<{
    (e: 'cancel'): void;
    (e: 'resume'): void;
}>();

const formattedEndsAt = computed(() => {
    if (!props.subscription?.endsAt) {
        return null;
    }

    return new Date(props.subscription.endsAt).toLocaleDateString(locale.value);
});

const formattedTrialEndsAt = computed(() => {
    if (!props.subscription?.trialEndsAt) {
        return null;
    }

    return new Date(props.subscription.trialEndsAt).toLocaleDateString(
        locale.value,
    );
});

/**
 * Stripe's status ("past_due") in words; an unknown one shows as Stripe sent it.
 */
const statusLabel = computed(() => {
    const status = props.subscription?.status ?? '';
    const key = `kinetix.billing_status_${status}`;

    return status !== '' && te(key) ? t(key) : status.replace(/_/g, ' ');
});

/** Cancelling is one click from losing the plan, so it asks first. */
const confirmingCancel = ref(false);

function confirmCancel(): void {
    confirmingCancel.value = false;
    emit('cancel');
}

const trialPlanName = computed(() => {
    return props.subscription?.trialPlan ?? null;
});

const hasActions = computed(() => {
    if (!props.subscription) {
        return false;
    }

    return (
        props.subscription.active ||
        props.subscription.onTrial ||
        props.subscription.onGracePeriod
    );
});
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{
                t('kinetix.billing_subscription_status')
            }}</CardTitle>
        </CardHeader>

        <CardContent class="space-y-4">
            <div v-if="subscription" class="gap-2 flex flex-col">
                <div class="flex items-center justify-between">
                    <span class="text-sm text-muted-foreground">{{
                        t('kinetix.billing_status')
                    }}</span>
                    <span
                        v-if="subscription.onTrial"
                        class="px-2 py-0.5 text-xs font-medium bg-amber-500/10 text-amber-500 inline-flex w-fit items-center rounded-full border border-transparent capitalize"
                    >
                        {{ t('kinetix.billing_trial') }}
                    </span>
                    <span
                        v-else
                        class="px-2 py-0.5 text-xs font-medium inline-flex w-fit items-center rounded-full border capitalize"
                        :class="
                            subscription.active && !subscription.onGracePeriod
                                ? 'border-transparent bg-primary text-primary-foreground'
                                : 'border-transparent bg-secondary text-secondary-foreground'
                        "
                    >
                        {{ statusLabel }}
                    </span>
                </div>

                <div
                    v-if="subscription.onTrial && formattedTrialEndsAt"
                    class="p-3 text-xs border-amber-500/20 bg-amber-500/5 text-amber-600 dark:text-amber-400 font-medium rounded-md border"
                >
                    <template
                        v-if="subscription.onGenericTrial && trialPlanName"
                    >
                        {{
                            t('kinetix.billing_trial_active_plan', {
                                plan: trialPlanName,
                                date: formattedTrialEndsAt,
                            })
                        }}
                    </template>
                    <template v-else>
                        {{
                            t('kinetix.billing_trial_active', {
                                date: formattedTrialEndsAt,
                            })
                        }}
                    </template>
                </div>

                <div
                    v-if="!subscription.onTrial && formattedEndsAt"
                    class="text-xs font-medium text-muted-foreground"
                >
                    {{ t('kinetix.billing_expires_on') }}: {{ formattedEndsAt }}
                </div>
            </div>
            <p v-else class="text-sm text-muted-foreground">
                {{
                    currentPlanName
                        ? t('kinetix.billing_on_free_plan', {
                              plan: currentPlanName,
                          })
                        : t('kinetix.billing_no_subscription')
                }}
            </p>
        </CardContent>

        <CardFooter v-if="hasActions" class="pt-6 border-t border-border">
            <button
                v-if="!subscription?.onGracePeriod"
                type="button"
                :class="
                    cn(
                        buttonVariants({ variant: 'outline' }),
                        'w-full text-destructive hover:bg-destructive/10',
                    )
                "
                @click="confirmingCancel = true"
            >
                {{ t('kinetix.billing_cancel_subscription') }}
            </button>
            <button
                v-else
                type="button"
                :class="cn(buttonVariants({ variant: 'outline' }), 'w-full')"
                @click="emit('resume')"
            >
                {{ t('kinetix.billing_resume_subscription') }}
            </button>
        </CardFooter>

        <KinetixConfirmModal
            :open="confirmingCancel"
            :heading="t('kinetix.billing_cancel_confirm_title')"
            :description="t('kinetix.billing_cancel_confirm_description')"
            :submit-label="t('kinetix.billing_cancel_subscription')"
            :cancel-label="t('kinetix.billing_keep_subscription')"
            @update:open="confirmingCancel = $event"
            @confirm="confirmCancel"
        />
    </Card>
</template>
