<script setup lang="ts">
import { Check, CreditCard, Plus, Trash2 } from '@lucide/vue';
import { nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixStripe } from '@/composables/useKinetixStripe';
import { buttonVariants } from '@/composables/useShadcnVariants';
import type { KinetixPaymentMethod } from '@/types/kinetix';
import Card from './primitives/Card.vue';
import CardContent from './primitives/CardContent.vue';
import CardDescription from './primitives/CardDescription.vue';
import CardHeader from './primitives/CardHeader.vue';
import CardTitle from './primitives/CardTitle.vue';
import { cn } from './primitives/cn';

const { t } = useI18n();

const props = withDefaults(
    defineProps<{
        paymentMethods?: KinetixPaymentMethod[];
        selectedId?: string;
        defaultId?: string;
        /** Stripe publishable key. */
        publishableKey: string;
        /** SetupIntent client secret used to confirm a new card. */
        setupClientSecret?: string;
        loading?: boolean;
    }>(),
    {
        paymentMethods: () => [],
        selectedId: 'new',
        defaultId: undefined,
        setupClientSecret: undefined,
        loading: false,
    },
);

const emit = defineEmits<{
    (e: 'select', id: string): void;
    (e: 'remove', id: string): void;
    (e: 'added', paymentMethodId: string): void;
    (e: 'error', message: string): void;
}>();

const cardMount = ref<HTMLDivElement | null>(null);
const submitting = ref(false);
let mounted = false;

const { ready, error, mount, confirmCardSetup } = useKinetixStripe({
    publishableKey: props.publishableKey,
});

watch(error, (message) => {
    if (message) {
        emit('error', message);
    }
});

/** Mount the Stripe Element lazily, the first time the "new card" panel shows. */
async function ensureMounted(): Promise<void> {
    if (mounted || !props.publishableKey) {
        return;
    }

    await nextTick();

    if (cardMount.value) {
        mounted = true;
        await mount(cardMount.value);
    }
}

watch(
    () => props.selectedId,
    (value) => {
        if (value === 'new') {
            void ensureMounted();
        }
    },
    { immediate: true },
);

async function handleAdd(): Promise<void> {
    if (!props.setupClientSecret || submitting.value) {
        return;
    }

    submitting.value = true;

    const { paymentMethodId, error: setupError } = await confirmCardSetup(
        props.setupClientSecret,
    );

    submitting.value = false;

    if (setupError) {
        emit('error', setupError);

        return;
    }

    if (paymentMethodId) {
        emit('added', paymentMethodId);
    }
}
</script>

<template>
    <Card>
        <CardHeader>
            <CardTitle>{{ t('kinetix.billing_payment_method') }}</CardTitle>
            <CardDescription>{{
                t('kinetix.billing_payment_method_desc')
            }}</CardDescription>
        </CardHeader>

        <CardContent>
            <div class="gap-4 sm:grid-cols-2 grid grid-cols-1">
                <div
                    v-for="pm in paymentMethods"
                    :key="pm.id"
                    class="rounded-xl p-4 relative flex cursor-pointer flex-col justify-between border-2 transition-colors"
                    :class="
                        selectedId === pm.id
                            ? 'border-primary bg-primary/5'
                            : 'border-border hover:border-muted-foreground'
                    "
                    @click="emit('select', pm.id)"
                >
                    <div class="flex items-start justify-between">
                        <div class="gap-2 flex items-center">
                            <CreditCard class="h-5 w-5" />
                            <span class="font-medium uppercase">{{
                                pm.brand
                            }}</span>
                        </div>
                        <div
                            v-if="selectedId === pm.id"
                            class="h-5 w-5 flex items-center justify-center rounded-full bg-primary"
                        >
                            <Check class="h-3 w-3 text-primary-foreground" />
                        </div>
                    </div>

                    <div class="mt-4">
                        <p class="font-mono text-lg tracking-widest">
                            •••• {{ pm.last4 }}
                        </p>
                        <div class="mt-2 flex items-center justify-between">
                            <span class="text-xs text-muted-foreground">
                                {{ pm.expMonth }}/{{ pm.expYear }}
                            </span>
                            <span
                                v-if="defaultId === pm.id"
                                class="px-2 py-0.5 font-medium inline-flex items-center rounded-md bg-secondary text-[10px] text-secondary-foreground uppercase"
                            >
                                {{ t('kinetix.billing_default') }}
                            </span>
                        </div>
                    </div>

                    <button
                        v-if="selectedId === pm.id"
                        type="button"
                        class="-right-2 -top-2 p-1 shadow-lg absolute z-20 rounded-full bg-destructive text-destructive-foreground"
                        @click.stop="emit('remove', pm.id)"
                    >
                        <Trash2 class="h-3 w-3" />
                    </button>
                </div>

                <div
                    class="rounded-xl p-6 flex cursor-pointer flex-col items-center justify-center border-2 border-dashed transition-colors"
                    :class="
                        selectedId === 'new'
                            ? 'border-primary bg-primary/5'
                            : 'border-border hover:border-muted-foreground'
                    "
                    @click="emit('select', 'new')"
                >
                    <Plus class="mb-2 h-8 w-8 text-muted-foreground" />
                    <span class="font-medium">{{
                        t('kinetix.billing_add_card')
                    }}</span>
                </div>
            </div>

            <div v-show="selectedId === 'new'" class="mt-8 space-y-4">
                <div class="rounded-lg p-4 border border-input bg-muted/40">
                    <div ref="cardMount"></div>
                </div>
                <button
                    type="button"
                    :class="cn(buttonVariants(), 'w-full')"
                    :disabled="loading || submitting || !ready"
                    @click="handleAdd"
                >
                    {{ t('kinetix.billing_add_payment_method') }}
                </button>
            </div>

            <p
                v-if="paymentMethods.length === 0 && selectedId !== 'new'"
                class="mt-4 p-3 text-xs rounded-md border border-border bg-muted/40 text-muted-foreground"
            >
                {{ t('kinetix.billing_payment_required') }}
            </p>
        </CardContent>
    </Card>
</template>
