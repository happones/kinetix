<script setup lang="ts">
import { computed, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import type { KinetixLocaleOption } from '@/types/kinetix';
import KinetixSelect from '../KinetixSelect.vue';

/**
 * Locale picker as a labelled form field — the variant for a settings page or a
 * profile form, where the language sits among other fields rather than in a
 * toolbar. Built on KinetixSelect so it inherits the same keyboard navigation
 * and styling as every other Select in the app.
 */
const props = withDefaults(
    defineProps<{
        locales: KinetixLocaleOption[];
        current: string;
        saving?: boolean;
        /** Render the field's visible label. Off renders an aria-label only. */
        showLabel?: boolean;
        /** Override the label / aria-label text. */
        label?: string | null;
    }>(),
    { saving: false, showLabel: true, label: null },
);

const emit = defineEmits<{
    (e: 'select', code: string): void;
}>();

const { t } = useI18n();

const fieldId = useId();

const labelText = computed(() => props.label ?? t('kinetix.language'));

// KinetixSelect takes `code => label` pairs, like every other Select.
const options = computed<Record<string, string>>(() =>
    Object.fromEntries(props.locales.map((loc) => [loc.code, loc.label])),
);
</script>

<template>
    <div class="gap-2 flex flex-col">
        <label
            v-if="props.showLabel"
            :for="fieldId"
            class="text-sm font-medium leading-none"
        >
            {{ labelText }}
        </label>

        <KinetixSelect
            :id="fieldId"
            :value="props.current"
            :options="options"
            :disabled="props.saving"
            :aria-label="props.showLabel ? undefined : labelText"
            @update:value="emit('select', $event)"
        />
    </div>
</template>
