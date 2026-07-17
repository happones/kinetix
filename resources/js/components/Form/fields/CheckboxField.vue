<script setup lang="ts">
import KinetixCheckbox from '../../KinetixCheckbox.vue';

defineProps<{ comp: any; value: any }>();

const emit = defineEmits<{ (e: 'update', value: any): void }>();
</script>

<template>
    <div class="space-x-2 py-1 flex items-center">
        <KinetixCheckbox
            :id="comp.name"
            :checked="!!value"
            :disabled="comp.isDisabled"
            @change="emit('update', $event)"
        />
    </div>
</template>
