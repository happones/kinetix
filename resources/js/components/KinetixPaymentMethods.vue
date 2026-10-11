<script setup lang="ts">
import { Check, CreditCard, Plus, Trash2 } from '@lucide/vue';
import { computed, nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { buttonVariants } from '@/composables/useKinetixShadcnVariants';
import { useKinetixStripe } from '@/composables/useKinetixStripe';
import type { KinetixPaymentMethod } from '@/types/kinetix';
import KinetixConfirmModal from './KinetixConfirmModal.vue';
import Card from './primitives/Card.vue';
import CardContent from './primitives/CardContent.vue';
import CardDescription from './primitives/CardDescription.vue';
import CardHeader from './primitives/CardHeader.vue';
import CardTitle from './primitives/CardTitle.vue';
import { cn } from './primitives/cn';

const { t, locale } = useI18n();

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
    locale: locale.value,
});

/**
 * A card can only be added with both a publishable key and a SetupIntent;
 * without them the panel says so instead of an empty field and a dead button.
 */
const cardsAvailable = computed(
    () => !!props.publishableKey && !!props.setupClientSecret,
);

/**
 * The last card error, shown under the field. It was only emitted, and a
 * page that did not listen (the generated one) declined cards in silence.
 */
const cardError = ref<string | null>(null);

watch(error, (message) => {
    if (message) {
        cardError.value = message;
        emit('error', message);
    }
});

/** Removing a card asks first: the trash button sits on the selected card. */
const removingId = ref<string | null>(null);

function confirmRemove(): void {
    if (removingId.value) {
        emit('remove', removingId.value);
    }

    removingId.value = null;
}

/** Mount the Stripe Element lazily, the first time the "new card" panel shows. */
async function ensureMounted(): Promise<void> {
    if (mounted || !cardsAvailable.value) {
        return;
    }

    await nextTick();

    if (cardMount.value) {
        mounted = true;
        await mount(cardMount.value);
    }
}

watch(
    [() => props.selectedId, cardsAvailable],
    ([value]) => {
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
    cardError.value = null;

    const { paymentMethodId, error: setupError } = await confirmCardSetup(
        props.setupClientSecret,
    );

    submitting.value = false;

    if (setupError) {
        cardError.value = setupError;
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
            <div
                class="gap-4 sm:grid-cols-2 grid grid-cols-1"
                role="radiogroup"
                :aria-label="t('kinetix.billing_payment_method')"
            >
                <div
                    v-for="pm in paymentMethods"
                    :key="pm.id"
                    role="radio"
                    :aria-checked="selectedId === pm.id"
                    tabindex="0"
                    class="rounded-xl p-4 relative flex cursor-pointer flex-col justify-between border-2 transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    :class="
                        selectedId === pm.id
                            ? 'border-primary bg-primary/5'
                            : 'border-border hover:border-muted-foreground'
                    "
                    @click="emit('select', pm.id)"
                    @keydown.enter.prevent="emit('select', pm.id)"
                    @keydown.space.prevent="emit('select', pm.id)"
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
                        class="-right-2 -top-2 p-1 shadow-lg text-white absolute z-20 rounded-full bg-destructive dark:bg-destructive/60"
                        :aria-label="t('kinetix.billing_remove_card')"
                        :title="t('kinetix.billing_remove_card')"
                        @click.stop="removingId = pm.id"
                    >
                        <Trash2 class="h-3 w-3" aria-hidden="true" />
                    </button>
                </div>

                <div
                    role="radio"
                    :aria-checked="selectedId === 'new'"
                    tabindex="0"
                    class="rounded-xl p-6 flex cursor-pointer flex-col items-center justify-center border-2 border-dashed transition-colors outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    :class="
                        selectedId === 'new'
                            ? 'border-primary bg-primary/5'
                            : 'border-border hover:border-muted-foreground'
                    "
                    @click="emit('select', 'new')"
                    @keydown.enter.prevent="emit('select', 'new')"
                    @keydown.space.prevent="emit('select', 'new')"
                >
                    <Plus class="mb-2 h-8 w-8 text-muted-foreground" />
                    <span class="font-medium">{{
                        t('kinetix.billing_add_card')
                    }}</span>
                </div>
            </div>

            <div v-show="selectedId === 'new'" class="mt-8 space-y-4">
                <template v-if="cardsAvailable">
                    <div class="rounded-lg p-4 border border-input bg-muted/40">
                        <div ref="cardMount"></div>
                    </div>
                    <p
                        v-if="cardError"
                        role="alert"
                        class="text-sm text-destructive"
                    >
                        {{ cardError }}
                    </p>
                    <button
                        type="button"
                        :class="cn(buttonVariants(), 'w-full')"
                        :disabled="loading || submitting || !ready"
                        @click="handleAdd"
                    >
                        {{ t('kinetix.billing_add_payment_method') }}
                    </button>
                </template>
                <p
                    v-else
                    class="p-3 text-sm rounded-md border border-border bg-muted/40 text-muted-foreground"
                >
                    {{ t('kinetix.billing_cards_unavailable') }}
                </p>
            </div>

            <p
                v-if="paymentMethods.length === 0 && selectedId !== 'new'"
                class="mt-4 p-3 text-xs rounded-md border border-border bg-muted/40 text-muted-foreground"
            >
                {{ t('kinetix.billing_payment_required') }}
            </p>
        </CardContent>

        <KinetixConfirmModal
            :open="removingId !== null"
            :heading="t('kinetix.billing_remove_card_confirm_title')"
            :description="t('kinetix.billing_remove_card_confirm_description')"
            :submit-label="t('kinetix.billing_remove_card')"
            @update:open="(value) => !value && (removingId = null)"
            @confirm="confirmRemove"
        />
    </Card>
</template>
