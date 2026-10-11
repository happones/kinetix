<script setup lang="ts">
import { computed } from 'vue';
import { textareaClass } from '@/composables/useKinetixShadcnVariants';

const props = defineProps<{ comp: any; value: any }>();

const emit = defineEmits<{ (e: 'update', value: any): void }>();

/**
 * `Textarea::rows()` as a minimum height. The shadcn textarea sizes itself to
 * its content (`field-sizing-content`), and that makes the browser ignore the
 * `rows` attribute, so a field asked for eight rows opened at two. `lh` is the
 * field's own line height; the rest is its vertical padding and border.
 */
const minHeight = computed(() => {
    const rows = Number(props.comp.extraInputAttributes?.rows);

    return rows > 0 ? { minHeight: `calc(${rows}lh + 1rem + 2px)` } : {};
});
</script>

<template>
    <textarea
        :id="comp.name"
        :value="value"
        :placeholder="comp.placeholder"
        :disabled="comp.isDisabled"
        v-bind="comp.extraInputAttributes"
        :class="textareaClass"
        :style="minHeight"
        @input="emit('update', ($event.target as HTMLTextAreaElement).value)"
    />
</template>
