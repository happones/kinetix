<script setup lang="ts">
import { ChevronsLeftRight, EyeOff } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import type { KinetixImportPreview } from '@/types/kinetix';
import KinetixButton from '../KinetixButton.vue';

/**
 * Step 3's sample table: the first few rows of the file, with the target column
 * each source column feeds.
 *
 * Bounded on both axes on purpose. Rows are capped server-side (the reader
 * stops at `settings.previewRows`, so a million-row file costs the same as a
 * ten-row one), and columns are capped here — a wide file shows the first
 * `previewColumns` and folds the rest behind a toggle instead of turning the
 * dialog into a horizontal scroll nobody reads. The table scrolls inside its
 * own container, so the dialog itself never scrolls sideways.
 */
const props = defineProps<{
    preview: KinetixImportPreview;
    /** Source columns shown before the rest fold away (0 = no cap). */
    maxColumns: number;
    /** Reverse lookup: the target column label a header feeds, if any. */
    columnForHeader: (index: number) => string | null;
}>();

const { t } = useI18n();

const showAllColumns = ref(false);

const isCapped = computed(
    () =>
        props.maxColumns > 0 &&
        props.preview.headers.length > props.maxColumns &&
        !showAllColumns.value,
);

/** Header indices rendered, in file order. */
const visibleIndexes = computed<number[]>(() => {
    const all = props.preview.headers.map((_, index) => index);

    return isCapped.value ? all.slice(0, props.maxColumns) : all;
});

const hiddenCount = computed(
    () => props.preview.headers.length - visibleIndexes.value.length,
);
</script>

<template>
    <div class="gap-2 flex flex-col">
        <div class="gap-2 flex flex-wrap items-center justify-between">
            <p class="text-xs text-muted-foreground">
                {{
                    t('kinetix.import_preview_sample', {
                        rows: preview.rows.length,
                        columns: visibleIndexes.length,
                        total: preview.headers.length,
                    })
                }}
            </p>
            <KinetixButton
                v-if="maxColumns > 0 && preview.headers.length > maxColumns"
                variant="ghost"
                size="sm"
                @click="showAllColumns = !showAllColumns"
            >
                <template #icon>
                    <ChevronsLeftRight v-if="isCapped" class="size-4" />
                    <EyeOff v-else class="size-4" />
                </template>
                {{
                    isCapped
                        ? t('kinetix.import_preview_show_all_columns', {
                              count: hiddenCount,
                          })
                        : t('kinetix.import_preview_show_fewer_columns')
                }}
            </KinetixButton>
        </div>

        <!-- The scroller is HERE, not on the dialog: wide content scrolls
             inside its own box so the page/panel never scrolls sideways. -->
        <div class="rounded-xl overflow-x-auto border border-border">
            <table class="text-sm min-w-full">
                <caption class="sr-only">
                    {{
                        t('kinetix.import_preview_caption')
                    }}
                </caption>
                <thead class="bg-muted/40">
                    <tr>
                        <th
                            scope="col"
                            class="px-3 py-2 text-xs font-semibold text-right text-muted-foreground"
                        >
                            {{ t('kinetix.import_preview_row_heading') }}
                        </th>
                        <th
                            v-for="index in visibleIndexes"
                            :key="index"
                            scope="col"
                            class="px-3 py-2 font-semibold text-left whitespace-nowrap text-foreground"
                        >
                            <span class="block">
                                {{
                                    preview.headers[index]?.trim() ||
                                    t('kinetix.import_unnamed_column', {
                                        number: index + 1,
                                    })
                                }}
                            </span>
                            <span
                                v-if="columnForHeader(index)"
                                class="mt-0.5 gap-1 font-medium flex items-center text-[10px] text-success"
                            >
                                <span aria-hidden="true">→</span>
                                {{ columnForHeader(index) }}
                            </span>
                            <span
                                v-else
                                class="mt-0.5 font-medium block text-[10px] text-muted-foreground"
                            >
                                {{ t('kinetix.import_preview_ignored') }}
                            </span>
                        </th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, rowIndex) in preview.rows"
                        :key="rowIndex"
                        class="border-t border-border"
                    >
                        <th
                            scope="row"
                            class="px-3 py-2 text-xs font-normal text-right text-muted-foreground tabular-nums"
                        >
                            {{ rowIndex + 1 }}
                        </th>
                        <td
                            v-for="index in visibleIndexes"
                            :key="index"
                            class="px-3 py-2 max-w-[18rem] truncate text-muted-foreground"
                            :class="
                                columnForHeader(index) ? 'bg-success/10' : ''
                            "
                            :title="row[index] ?? undefined"
                        >
                            {{ row[index] }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <p
            v-if="preview.rows.length === 0"
            class="text-xs text-muted-foreground"
        >
            {{ t('kinetix.import_preview_empty') }}
        </p>
    </div>
</template>
