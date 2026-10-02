<script setup lang="ts">
import { Lock } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { requestConfidentialUnlock } from '@/composables/useKinetixConfidential';
import type {
    KinetixTableCellColumn,
    KinetixTableCellDescription,
    KinetixTableCellRecord,
} from '@/types/kinetix';
import KinetixCopyable from '../../primitives/KinetixCopyable.vue';

const props = defineProps<{
    col: KinetixTableCellColumn;
    record: KinetixTableCellRecord;
}>();

const { t } = useI18n();

const description = computed<KinetixTableCellDescription | null>(
    () => props.record.descriptions[props.col.name] ?? null,
);

const value = computed<unknown>(() => props.record.values[props.col.name]);

/**
 * The cell is a flex column, so the td's text-align never reaches its items —
 * `alignment()` has to align them on the cross axis instead.
 */
const ALIGN_ITEMS: Record<string, string> = {
    center: 'items-center',
    right: 'items-end',
};

const url = computed<string | null>(
    () => props.record.urls?.[props.col.name] ?? null,
);

const isCopyable = computed<boolean>(
    () => !!props.col.isCopyable && value.value != null,
);

/**
 * A plain value IS the copy trigger; a link or rich HTML keeps its own clicks
 * and gets the icon-only trigger beside it instead.
 */
const copiesInline = computed<boolean>(
    () => isCopyable.value && !url.value && !props.col.isHtml,
);

/** HTML copies as the text the user sees, never as markup. */
const copyText = computed<string>(() =>
    props.col.isHtml
        ? (new DOMParser().parseFromString(String(value.value), 'text/html')
              .body.textContent ?? '')
        : String(value.value),
);
</script>

<template>
    <div
        class="flex flex-col"
        :class="ALIGN_ITEMS[col.alignment ?? '']"
        :title="col.tooltip ?? undefined"
    >
        <span
            v-if="description?.position === 'above'"
            class="mb-0.5 text-[11px] text-muted-foreground"
        >
            {{ description?.text }}
        </span>
        <span
            class="group/copy gap-1.5 inline-flex items-center"
            :class="col.wrap ? 'break-words whitespace-normal' : ''"
        >
            <KinetixCopyable v-if="copiesInline" :value="copyText">
                {{ value }}
            </KinetixCopyable>
            <template v-else>
                <!-- html(): the value is trusted (sanitize user content server-side). -->
                <span v-if="col.isHtml" v-html="value" />
                <a
                    v-else-if="url"
                    :href="url"
                    :target="col.openUrlInNewTab ? '_blank' : undefined"
                    :rel="
                        col.openUrlInNewTab ? 'noopener noreferrer' : undefined
                    "
                    class="font-medium text-info hover:underline"
                    @click.stop
                >
                    {{ value }}
                </a>
                <template v-else>{{ value }}</template>
                <KinetixCopyable
                    v-if="isCopyable"
                    :value="copyText"
                    class="opacity-0 group-focus-within/copy:opacity-100 group-hover/copy:opacity-100 focus-visible:opacity-100 pointer-coarse:opacity-100"
                />
            </template>
            <button
                v-if="col.isConfidential"
                type="button"
                class="text-muted-foreground opacity-0 transition-opacity group-focus-within/copy:opacity-100 group-hover/copy:opacity-100 hover:text-foreground focus-visible:opacity-100"
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
