<script setup lang="ts">
import { inputClass } from '@/composables/useKinetixShadcnVariants';
import KinetixCopyableInput from '../../KinetixCopyableInput.vue';

defineProps<{ comp: any; value: any }>();

const emit = defineEmits<{ (e: 'update', value: any): void }>();
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
        :class="inputClass"
        @input="emit('update', ($event.target as HTMLInputElement).value)"
    />
</template>
