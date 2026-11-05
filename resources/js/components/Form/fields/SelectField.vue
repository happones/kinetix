<script setup lang="ts">
import { computed } from 'vue';
import type { Component } from 'vue';
import { kinetixComponentOf } from '@/composables/kinetixLazy';
import { KINETIX_SELECT_CONTROL_LOADERS } from '../kinetixFormFieldLoaders';

const props = defineProps<{ comp: any; value: any }>();

const emit = defineEmits<{ (e: 'update', value: any): void }>();

/**
 * Searchable → Combobox (local or remote); otherwise a plain Select. Each
 * control downloads on first use (KinetixForm starts the right one from the
 * schema). Resolved from `isSearchable` alone, so the loading wrapper is never
 * swapped for the real control mid-edit.
 */
const searchable = computed<boolean>(() => !!props.comp.isSearchable);

const control = computed<Component>(() =>
    kinetixComponentOf(
        searchable.value
            ? KINETIX_SELECT_CONTROL_LOADERS.combobox
            : KINETIX_SELECT_CONTROL_LOADERS.select,
    ),
);
</script>

<template>
    <component
        :is="control"
        v-if="searchable"
        :id="comp.name"
        :value="value"
        :options="comp.options"
        :placeholder="comp.placeholder"
        :disabled="comp.isDisabled"
        :search-token="comp.searchToken"
        @update:value="emit('update', $event)"
    />

    <component
        :is="control"
        v-else
        :id="comp.name"
        :value="value"
        :options="comp.options"
        :placeholder="comp.placeholder"
        :disabled="comp.isDisabled"
        @update:value="emit('update', $event)"
    />
</template>
