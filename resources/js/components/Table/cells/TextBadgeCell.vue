<script setup lang="ts">
import { Copy } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { statusBadgeClass } from '@/composables/useKinetixStatusColor';
import type {
    KinetixTableCellColumn,
    KinetixTableCellRecord,
} from '@/types/kinetix';

const props = defineProps<{
    col: KinetixTableCellColumn;
    record: KinetixTableCellRecord;
}>();

const emit = defineEmits<{
    (e: 'copy-to-clipboard', value: string): void;
}>();

const { t } = useI18n();

// An ARRAY state (TagsInput, CheckboxList, multi-Select) renders one pill per
// item — the server keeps the array for badge columns on purpose.
const items = computed<unknown[]>(() => {
    const value = props.record.values[props.col.name];

    return Array.isArray(value) ? value : [value];
});
</script>

<template>
    <span
        class="group/copy gap-1 inline-flex flex-wrap items-center"
        :title="col.tooltip ?? undefined"
    >
        <span
            v-for="(item, i) in items"
            :key="i"
            class="px-2 py-0.5 text-xs font-semibold inline-flex items-center rounded-full"
            :class="statusBadgeClass(record.badgeColors[col.name])"
        >
            {{ item }}
        </span>
        <button
            v-if="col.isCopyable && record.values[col.name] != null"
            type="button"
            class="text-muted-foreground opacity-0 transition-opacity group-hover/copy:opacity-100 hover:text-foreground"
            :title="t('kinetix.copy')"
            @click.stop="
                emit('copy-to-clipboard', items.map(String).join(', '))
            "
        >
            <Copy class="size-3.5" />
        </button>
    </span>
</template>
