<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import type { KinetixSummary, KinetixTableColumn } from '@/types/kinetix';

defineProps<{
    columnsToRender: KinetixTableColumn[];
    summaries: Record<string, KinetixSummary[]> | undefined;
    reorderable?: boolean;
    hasBulkActions: boolean;
    hasRecordActions: boolean;
}>();

const { t } = useI18n();
</script>

<template>
    <tfoot class="font-semibold border-t-2 border-border bg-muted/40">
        <tr>
            <td v-if="reorderable" class="w-8 px-2 py-3" />
            <td v-if="hasBulkActions" class="w-10 px-4 py-3" />
            <td
                v-for="(col, ci) in columnsToRender"
                :key="col.name"
                class="px-6 py-3 text-sm whitespace-nowrap"
                :class="[
                    col.alignment === 'center' ? 'text-center' : '',
                    col.alignment === 'right' ? 'text-right' : 'text-left',
                ]"
            >
                <template v-if="summaries?.[col.name]">
                    <div v-for="(s, si) in summaries?.[col.name]" :key="si">
                        <span v-if="s.label" class="text-muted-foreground"
                            >{{ s.label }}: </span
                        >{{ s.value }}
                    </div>
                </template>
                <span v-else-if="ci === 0" class="text-muted-foreground">
                    {{ t('kinetix.summary_total') }}
                </span>
            </td>
            <td v-if="hasRecordActions" class="px-6 py-3" />
        </tr>
    </tfoot>
</template>
