<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import type { KinetixTableFilter } from '@/types/kinetix';
import KinetixCombobox from '../../KinetixCombobox.vue';
import KinetixSelect from '../../KinetixSelect.vue';

const props = defineProps<{
    filter: KinetixTableFilter;
    value: unknown;
}>();

const emit = defineEmits<{
    (e: 'update', value: unknown): void;
}>();

const { t } = useI18n();

// Select/ternary filters prepend an "All" sentinel so clearing is one click.
const optionsWithAll = computed<Record<string, string>>(() => ({
    '': t('kinetix.all'),
    ...(props.filter.options || {}),
}));
</script>

<template>
    <KinetixCombobox
        v-if="filter.isSearchable"
        :value="(value as string) ?? ''"
        :options="optionsWithAll"
        :search-token="filter.searchToken"
        @update:value="emit('update', $event)"
    />

    <KinetixSelect
        v-else
        :value="(value as string) ?? ''"
        :options="optionsWithAll"
        @update:value="emit('update', $event)"
    />
</template>
