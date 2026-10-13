<script setup lang="ts">
import { computed } from 'vue';
import {
    applyConstraintCase,
    constraintInputAttributes,
} from '@/composables/kinetixFieldConstraints';
import { inputClass } from '@/composables/useKinetixShadcnVariants';
import KinetixCopyableInput from '../../KinetixCopyableInput.vue';

const props = defineProps<{ comp: any; value: any }>();

const emit = defineEmits<{ (e: 'update', value: any): void }>();

// The keyboard and autocorrect the field's rules call for.
const ruleAttrs = computed(() =>
    constraintInputAttributes(props.comp.constraints),
);

/**
 * `lowercase`/`uppercase` applied as the user types, keeping the caret where
 * it was (rewriting the value would otherwise jump it to the end).
 */
const onInput = (event: Event): void => {
    const input = event.target as HTMLInputElement;
    const cased = applyConstraintCase(props.comp.constraints, input.value);

    if (cased !== input.value) {
        const { selectionStart, selectionEnd } = input;
        input.value = cased;
        input.setSelectionRange(selectionStart, selectionEnd);
    }

    emit('update', cased);
};
</script>

<template>
    <!-- Copyable/revealable variant -->
    <KinetixCopyableInput
        v-if="comp.isCopyable || comp.isRevealable"
        :id="comp.name"
        :value="value"
        :input-type="comp.inputType || 'text'"
        :placeholder="comp.placeholder"
        :disabled="comp.isDisabled"
        :copyable="comp.isCopyable"
        :revealable="comp.isRevealable"
        @update:value="emit('update', $event)"
    />

    <input
        v-else
        :id="comp.name"
        :value="value"
        :type="comp.inputType || 'text'"
        :placeholder="comp.placeholder"
        :disabled="comp.isDisabled"
        v-bind="ruleAttrs"
        :class="inputClass"
        @input="onInput"
    />
</template>
