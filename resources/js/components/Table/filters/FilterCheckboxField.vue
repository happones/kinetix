<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { KinetixTableFilter } from '@/types';
import KinetixCheckbox from '../../KinetixCheckbox.vue';

defineProps<{
    filter: KinetixTableFilter;
    value: unknown;
}>();

const emit = defineEmits<{
    (e: 'update', value: unknown): void;
}>();

const { t } = useI18n();
</script>

<template>
    <div class="gap-2 mt-1 flex items-center">
        <KinetixCheckbox
            :id="'filter-' + filter.name"
            :checked="!!value"
            @change="emit('update', $event)"
        />
        <label
            :for="'filter-' + filter.name"
            class="text-xs cursor-pointer text-foreground select-none"
        >
            {{ t('kinetix.enable_filter') }}
        </label>
    </div>
</template>
