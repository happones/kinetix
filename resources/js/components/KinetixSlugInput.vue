<script setup lang="ts">
import { ref, watch } from 'vue';
import { inputClass } from '@/composables/useShadcnVariants';

/** Serialized slug config. */
interface SlugConfig {
    from?: string | null;
    separator?: string | null;
}

/**
 * URL-slug text input. While untouched, it mirrors a slugified version of the
 * source field's value; once the user edits it, auto-sync stops.
 */
const props = withDefaults(
    defineProps<{
        value?: string | null;
        /** Current value of the source field (resolved by KinetixFormSchema). */
        source?: string | null;
        config?: SlugConfig | null;
        disabled?: boolean;
        placeholder?: string | null;
    }>(),
    {
        value: null,
        source: null,
        config: null,
        disabled: false,
        placeholder: null,
    },
);

const emit = defineEmits<{ (e: 'update:value', value: string): void }>();

const separator = () => props.config?.separator || '-';

function slugify(text: string): string {
    const sep = separator();

    return (text ?? '')
        .toString()
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '') // strip accents
        .toLowerCase()
        .trim()
        .replace(/[^a-z0-9]+/g, sep)
        .replace(new RegExp(`\\${sep}+`, 'g'), sep)
        .replace(new RegExp(`^\\${sep}|\\${sep}$`, 'g'), '');
}

// Auto-sync stays on until the user types into the slug field.
const manual = ref(!!props.value);

watch(
    () => props.source,
    (next) => {
        if (!manual.value && props.config?.from) {
            emit('update:value', slugify(next ?? ''));
        }
    },
);

function onInput(event: Event): void {
    manual.value = true;
    emit('update:value', slugify((event.target as HTMLInputElement).value));
}
</script>

<template>
    <input
        :value="value ?? ''"
        type="text"
        :disabled="disabled"
        :placeholder="placeholder ?? ''"
        :class="inputClass"
        inputmode="url"
        @input="onInput"
    />
</template>
