<script setup lang="ts">
import KinetixCombobox from '../../KinetixCombobox.vue';
import KinetixSelect from '../../KinetixSelect.vue';

defineProps<{ comp: any; value: any }>();

const emit = defineEmits<{ (e: 'update', value: any): void }>();
</script>

<template>
    <!-- Searchable → Combobox (local or remote); otherwise a plain Select -->
    <KinetixCombobox
        v-if="comp.isSearchable"
        :id="comp.name"
        :value="value"
        :options="comp.options"
        :placeholder="comp.placeholder"
        :disabled="comp.isDisabled"
        :search-token="comp.searchToken"
        @update:value="emit('update', $event)"
    />

    <KinetixSelect
        v-else
        :id="comp.name"
        :value="value"
        :options="comp.options"
        :placeholder="comp.placeholder"
        :disabled="comp.isDisabled"
        @update:value="emit('update', $event)"
    />
</template>
