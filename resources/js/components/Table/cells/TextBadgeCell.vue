<script setup lang="ts">
import { computed } from 'vue';
import type {
    KinetixTableCellColumn,
    KinetixTableCellRecord,
} from '@/types/kinetix';
import KinetixBadge from '../../primitives/KinetixBadge.vue';
import KinetixCopyable from '../../primitives/KinetixCopyable.vue';

const props = defineProps<{
    col: KinetixTableCellColumn;
    record: KinetixTableCellRecord;
}>();

// An ARRAY state (TagsInput, CheckboxList, multi-Select) renders one pill per
// item — the server keeps the array for badge columns on purpose.
const items = computed<unknown[]>(() => {
    const value = props.record.values[props.col.name];

    return Array.isArray(value) ? value : [value];
});

const isCopyable = computed<boolean>(
    () => !!props.col.isCopyable && props.record.values[props.col.name] != null,
);
</script>

<template>
    <!-- Copyable: the pills themselves are the copy trigger. -->
    <component
        :is="isCopyable ? KinetixCopyable : 'span'"
        v-bind="isCopyable ? { value: items.map(String).join(', ') } : {}"
        :title="col.tooltip ?? undefined"
    >
        <span class="gap-1 inline-flex flex-wrap items-center">
            <KinetixBadge
                v-for="(item, i) in items"
                :key="i"
                :color="record.badgeColors[col.name]"
            >
                {{ item }}
            </KinetixBadge>
        </span>
    </component>
</template>
