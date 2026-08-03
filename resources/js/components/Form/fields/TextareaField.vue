<script setup lang="ts">
import { textareaClass } from '@/composables/useKinetixShadcnVariants';

defineProps<{ comp: any; value: any }>();

const emit = defineEmits<{ (e: 'update', value: any): void }>();
</script>

<template>
    <textarea
        :id="comp.name"
        :value="value"
        :placeholder="comp.placeholder"
        :disabled="comp.isDisabled"
        v-bind="comp.extraInputAttributes"
        :class="textareaClass"
        @input="emit('update', ($event.target as HTMLTextAreaElement).value)"
    />
</template>
