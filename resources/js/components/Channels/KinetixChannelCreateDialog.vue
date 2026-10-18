<script setup lang="ts">
import { computed, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixChannels } from '@/composables/useKinetixChannels';
import { inputClass } from '@/composables/useKinetixShadcnVariants';
import type {
    KinetixChannel,
    KinetixChannelLink,
    KinetixChannelProvider,
} from '@/types/kinetix';
import KinetixButton from '../KinetixButton.vue';
import KinetixLabel from '../KinetixLabel.vue';
import KinetixModal from '../primitives/KinetixModal.vue';
import KinetixChannelProviderIcon from './KinetixChannelProviderIcon.vue';

/**
 * Adding a line: pick where it lives (each provider says how it links), name
 * it, and — for a bot — paste its token. The line is set up with its provider
 * on submit; one linked by QR opens straight on its first code.
 */
const props = defineProps<{
    open: boolean;
    providers: KinetixChannelProvider[];
}>();

const emit = defineEmits<{
    (e: 'update:open', value: boolean): void;
    (
        e: 'created',
        channel: KinetixChannel,
        link: KinetixChannelLink | null,
    ): void;
}>();

const { t } = useI18n();
const { create } = useKinetixChannels();

const provider = ref<string>('');
const name = ref('');
const token = ref('');
const processing = ref(false);
const errors = ref<Record<string, string>>({});

const selected = computed(
    () => props.providers.find((p) => p.key === provider.value) ?? null,
);
const needsToken = computed(
    () => selected.value?.methods.includes('token') ?? false,
);

watch(
    () => props.open,
    (open) => {
        if (open) {
            provider.value = props.providers[0]?.key ?? '';
            name.value = '';
            token.value = '';
            errors.value = {};
        }
    },
    { immediate: true },
);

const hint = (p: KinetixChannelProvider): string =>
    p.methods.includes('token')
        ? t('kinetix.channel_method_token')
        : t('kinetix.channel_method_qr');

async function submit(): Promise<void> {
    processing.value = true;
    errors.value = {};

    try {
        const result = await create({
            provider: provider.value,
            name: name.value,
            ...(needsToken.value ? { token: token.value } : {}),
        });

        if (result?.channel) {
            emit('created', result.channel, result.link);
            emit('update:open', false);
        }
    } catch (e) {
        const bag = (e as { errors?: Record<string, string[]> }).errors;

        errors.value = bag
            ? Object.fromEntries(
                  Object.entries(bag).map(([key, value]) => [key, value[0]]),
              )
            : { provider: e instanceof Error ? e.message : String(e) };
    } finally {
        processing.value = false;
    }
}
</script>

<template>
    <KinetixModal
        :open="open"
        :title="t('kinetix.channel_add')"
        :description="t('kinetix.channel_add_description')"
        :processing="processing"
        @update:open="emit('update:open', $event)"
    >
        <form
            id="kinetix-channel-create"
            class="space-y-5"
            @submit.prevent="submit"
        >
            <fieldset v-if="providers.length > 1" class="space-y-2">
                <legend class="text-sm font-medium mb-2 text-foreground">
                    {{ t('kinetix.channel_choose_provider') }}
                </legend>
                <div class="gap-2 sm:grid-cols-2 grid grid-cols-1">
                    <label
                        v-for="p in providers"
                        :key="p.key"
                        class="gap-3 rounded-lg p-3 flex cursor-pointer items-center border transition-colors has-[:focus-visible]:ring-[3px] has-[:focus-visible]:ring-ring/50"
                        :class="
                            provider === p.key
                                ? 'border-primary bg-primary/5'
                                : 'border-border hover:bg-accent/50'
                        "
                        :data-test="`channel-provider-${p.key}`"
                    >
                        <input
                            v-model="provider"
                            type="radio"
                            name="provider"
                            :value="p.key"
                            class="sr-only"
                        />
                        <KinetixChannelProviderIcon :provider="p.key" />
                        <span class="min-w-0">
                            <span
                                class="text-sm font-medium block text-foreground"
                                >{{ p.label }}</span
                            >
                            <span class="text-xs block text-muted-foreground">{{
                                hint(p)
                            }}</span>
                        </span>
                    </label>
                </div>
            </fieldset>

            <div class="space-y-2">
                <KinetixLabel for="kinetix-channel-name">{{
                    t('kinetix.channel_name')
                }}</KinetixLabel>
                <input
                    id="kinetix-channel-name"
                    v-model="name"
                    type="text"
                    maxlength="100"
                    required
                    :placeholder="t('kinetix.channel_name_placeholder')"
                    :aria-invalid="!!errors.name"
                    :aria-describedby="
                        errors.name ? 'kinetix-channel-name-error' : undefined
                    "
                    :class="inputClass"
                />
                <p
                    v-if="errors.name"
                    id="kinetix-channel-name-error"
                    class="text-sm text-destructive"
                >
                    {{ errors.name }}
                </p>
            </div>

            <div v-if="needsToken" class="space-y-2">
                <KinetixLabel for="kinetix-channel-token">{{
                    t('kinetix.channel_token')
                }}</KinetixLabel>
                <input
                    id="kinetix-channel-token"
                    v-model="token"
                    type="password"
                    autocomplete="off"
                    spellcheck="false"
                    required
                    placeholder="123456789:AAH…"
                    :aria-invalid="!!errors.token"
                    aria-describedby="kinetix-channel-token-help"
                    :class="[inputClass, 'font-mono']"
                />
                <p
                    v-if="errors.token"
                    class="text-sm text-destructive"
                    role="alert"
                >
                    {{ errors.token }}
                </p>
                <p
                    id="kinetix-channel-token-help"
                    class="text-xs text-muted-foreground"
                >
                    {{ t('kinetix.channel_token_help') }}
                </p>
            </div>

            <p
                v-if="errors.provider"
                class="text-sm text-destructive"
                role="alert"
            >
                {{ errors.provider }}
            </p>
        </form>

        <template #footer>
            <KinetixButton
                variant="outline"
                :disabled="processing"
                @click="emit('update:open', false)"
            >
                {{ t('kinetix.cancel') }}
            </KinetixButton>
            <KinetixButton
                type="submit"
                form="kinetix-channel-create"
                :loading="processing"
                data-test="channel-create-submit"
            >
                {{ t('kinetix.channel_create') }}
            </KinetixButton>
        </template>
    </KinetixModal>
</template>
