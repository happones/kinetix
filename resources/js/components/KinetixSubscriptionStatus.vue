<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { buttonVariants } from '@/composables/useShadcnVariants';
import type { KinetixSubscriptionData } from '@/types';
import Card from './primitives/Card.vue';
import CardContent from './primitives/CardContent.vue';
import CardFooter from './primitives/CardFooter.vue';
import CardHeader from './primitives/CardHeader.vue';
import CardTitle from './primitives/CardTitle.vue';
import { cn } from './primitives/cn';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        subscription?: KinetixSubscriptionData | null;
    }>(),
    {
        subscription: null,
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

    return new Date(props.subscription.endsAt).toLocaleDateString();
});

const formattedTrialEndsAt = computed(() => {
    if (!props.subscription?.trialEndsAt) {
        return null;
    }

    return new Date(props.subscription.trialEndsAt).toLocaleDateString();
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
                        {{ subscription.status }}
                    </span>
                </div>

                <div
                    v-if="subscription.onTrial && formattedTrialEndsAt"
                    class="p-3 text-xs border-amber-500/20 bg-amber-500/5 text-amber-600 dark:text-amber-400 font-medium rounded-md border"
                >
                    {{
                        t('kinetix.billing_trial_active', {
                            date: formattedTrialEndsAt,
                        })
                    }}
                </div>

                <div
                    v-if="!subscription.onTrial && formattedEndsAt"
                    class="text-xs font-medium text-muted-foreground"
                >
                    {{ t('kinetix.billing_expires_on') }}: {{ formattedEndsAt }}
                </div>
            </div>
            <p v-else class="text-sm text-muted-foreground italic">
                {{ t('kinetix.billing_no_subscription') }}
            </p>
        </CardContent>

        <CardFooter v-if="subscription" class="pt-6 border-t border-border">
            <button
                v-if="!subscription.onGracePeriod"
                type="button"
                :class="
                    cn(
                        buttonVariants({ variant: 'outline' }),
                        'w-full text-destructive hover:bg-destructive/10',
                    )
                "
                @click="emit('cancel')"
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
    </Card>
</template>
