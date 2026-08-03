<script setup lang="ts">
import { Check, Copy, Eye, EyeOff } from '@lucide/vue';
import { computed, onBeforeUnmount, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { inputClass } from '@/composables/useKinetixShadcnVariants';

/**
 * A text input with a click-to-copy button and/or a reveal toggle. When
 * `revealable` the value is masked (password-style) until revealed — ideal for
 * API keys, tokens and secrets. Used by TextInput's `copyable()`/`revealable()`.
 */
const props = withDefaults(
    defineProps<{
        id?: string;
        value?: string | null;
        inputType?: string;
        placeholder?: string;
        disabled?: boolean;
        copyable?: boolean;
        revealable?: boolean;
    }>(),
    {
        id: undefined,
        value: '',
        inputType: 'text',
        placeholder: undefined,
        disabled: false,
        copyable: false,
        revealable: false,
    },
);

const emit = defineEmits<{
    (e: 'update:value', value: string): void;
}>();

const { t } = useI18n();

const revealed = ref(false);
const copied = ref(false);

const resolvedType = computed(() =>
    props.revealable && !revealed.value ? 'password' : props.inputType,
);

let copiedTimer: ReturnType<typeof setTimeout> | null = null;

async function copy(): Promise<void> {
    try {
        await navigator.clipboard.writeText(String(props.value ?? ''));
        copied.value = true;

        if (copiedTimer) {
            clearTimeout(copiedTimer);
        }

        copiedTimer = setTimeout(() => (copied.value = false), 1500);
    } catch {
        // clipboard unavailable — silently ignore
    }
}

onBeforeUnmount(() => {
    if (copiedTimer) {
        clearTimeout(copiedTimer);
        copiedTimer = null;
    }
});
</script>

<template>
    <div class="relative">
        <input
            :id="id"
            :value="value"
            :type="resolvedType"
            :placeholder="placeholder"
            :disabled="disabled"
            :class="[inputClass, 'pr-16']"
            @input="
                emit('update:value', ($event.target as HTMLInputElement).value)
            "
        />
        <div
            class="inset-y-0 right-0 gap-0.5 pr-1.5 absolute flex items-center"
        >
            <button
                v-if="revealable"
                type="button"
                class="size-7 flex items-center justify-center rounded-md text-muted-foreground transition-colors hover:text-foreground"
                :title="revealed ? t('kinetix.hide') : t('kinetix.reveal')"
                :aria-label="revealed ? t('kinetix.hide') : t('kinetix.reveal')"
                @click="revealed = !revealed"
            >
                <EyeOff v-if="revealed" class="size-4" />
                <Eye v-else class="size-4" />
            </button>
            <button
                v-if="copyable"
                type="button"
                class="size-7 flex items-center justify-center rounded-md text-muted-foreground transition-colors hover:text-foreground"
                :title="t('kinetix.copy')"
                :aria-label="t('kinetix.copy')"
                @click="copy"
            >
                <Check v-if="copied" class="size-4 text-green-500" />
                <Copy v-else class="size-4" />
            </button>
        </div>
    </div>
</template>
