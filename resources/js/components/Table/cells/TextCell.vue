<script setup lang="ts">
import { Copy, Lock } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { requestConfidentialUnlock } from '@/composables/useKinetixConfidential';
import type {
    KinetixTableCellColumn,
    KinetixTableCellDescription,
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

const description = computed<KinetixTableCellDescription | null>(
    () => props.record.descriptions[props.col.name] ?? null,
);
</script>

<template>
    <div class="flex flex-col">
        <span
            v-if="description?.position === 'above'"
            class="mb-0.5 text-[11px] text-muted-foreground"
        >
            {{ description?.text }}
        </span>
        <span class="group/copy gap-1.5 inline-flex items-center">
            {{ record.values[col.name] }}
            <button
                v-if="col.isCopyable && record.values[col.name] != null"
                type="button"
                class="text-muted-foreground opacity-0 transition-opacity group-hover/copy:opacity-100 hover:text-foreground"
                :title="t('kinetix.copy')"
                @click.stop="
                    emit('copy-to-clipboard', String(record.values[col.name]))
                "
            >
                <Copy class="size-3.5" />
            </button>
            <button
                v-if="col.isConfidential"
                type="button"
                class="text-muted-foreground opacity-0 transition-opacity group-hover/copy:opacity-100 hover:text-foreground"
                :title="t('kinetix.confidential_unlock')"
                @click.stop="requestConfidentialUnlock()"
            >
                <Lock class="size-3.5" />
            </button>
        </span>
        <span
            v-if="description?.position === 'below'"
            class="mt-0.5 text-[11px] text-muted-foreground"
        >
            {{ description?.text }}
        </span>
    </div>
</template>
