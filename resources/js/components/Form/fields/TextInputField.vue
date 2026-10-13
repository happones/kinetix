<script setup lang="ts">
import { Check } from '@lucide/vue';
import { computed, inject, useAttrs } from 'vue';
import { useI18n } from 'vue-i18n';
import {
    applyConstraintCase,
    CODE_FORMATS,
    constraintInputAttributes,
    fieldRequirements,
    formatHolds,
} from '@/composables/kinetixFieldConstraints';
import { inputClass } from '@/composables/useKinetixShadcnVariants';
import KinetixCopyableInput from '../../KinetixCopyableInput.vue';
import KinetixPasswordInput from '../../KinetixPasswordInput.vue';

const props = defineProps<{ comp: any; value: any }>();

const emit = defineEmits<{ (e: 'update', value: any): void }>();

// The field's aria wiring goes on the input itself, whichever variant renders.
defineOptions({ inheritAttrs: false });
const attrs = useAttrs();

// A code (an IP, a UUID…): monospace, with a tick once it's well formed.
const isCode = computed(() =>
    CODE_FORMATS.includes(props.comp.constraints?.format),
);
const codeHolds = computed(
    () =>
        isCode.value &&
        formatHolds(props.comp.constraints?.format, props.value) === true,
);

const { t, locale } = useI18n();
const fieldLabel = inject<(name: string) => string>(
    'kinetixFieldLabel',
    (name) => name,
);

// A password field gets the show/hide toggle, and its policy as a checklist.
const isPassword = computed(
    () =>
        props.comp.inputType === 'password' &&
        !props.comp.isCopyable &&
        !props.comp.isRevealable,
);
const passwordRequirements = computed(() =>
    isPassword.value && props.comp.constraints?.password
        ? fieldRequirements(props.comp.constraints, t, fieldLabel, {
              locale: locale.value,
          })
        : [],
);
const passwordAutocomplete = computed(
    () =>
        props.comp.extraInputAttributes?.autocomplete ??
        (/current|old/.test(props.comp.name)
            ? 'current-password'
            : 'new-password'),
);

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
        v-bind="attrs"
        @update:value="emit('update', $event)"
    />

    <KinetixPasswordInput
        v-else-if="isPassword"
        :id="comp.name"
        :value="value"
        :placeholder="comp.placeholder"
        :disabled="comp.isDisabled"
        :requirements="passwordRequirements"
        :autocomplete="passwordAutocomplete"
        v-bind="attrs"
        @update:value="emit('update', $event)"
    />

    <div v-else class="relative">
        <input
            :id="comp.name"
            :value="value"
            :type="comp.inputType || 'text'"
            :placeholder="comp.placeholder"
            :disabled="comp.isDisabled"
            v-bind="{ ...ruleAttrs, ...attrs }"
            :class="[inputClass, isCode ? 'pr-9 font-mono' : '']"
            @input="onInput"
        />
        <Check
            v-if="codeHolds"
            class="right-3 size-4 pointer-events-none absolute top-1/2 -translate-y-1/2 text-success"
            aria-hidden="true"
        />
    </div>
</template>
