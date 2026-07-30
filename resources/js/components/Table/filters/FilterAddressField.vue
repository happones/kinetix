<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { KinetixTableFilter } from '@/types/kinetix';

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
    <!-- Free-text search across the address columns. -->
    <input
        type="text"
        :value="(value as string) || ''"
        :placeholder="t('kinetix.address_search')"
        class="text-xs p-2 w-full rounded-md border border-border bg-background text-foreground transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
        @input="emit('update', ($event.target as HTMLInputElement).value)"
    />
</template>
