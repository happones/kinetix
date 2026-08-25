<script setup lang="ts">
import {
    AlertTriangle,
    ArrowRight,
    CheckCircle2,
    RotateCcw,
    Search,
} from '@lucide/vue';
import { computed, ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import { inputClass } from '@/composables/useKinetixShadcnVariants';
import type { KinetixImportColumn } from '@/types/kinetix';
import KinetixButton from '../KinetixButton.vue';
import KinetixLabel from '../KinetixLabel.vue';
import KinetixSelect from '../KinetixSelect.vue';

/**
 * Step 2: which source column feeds each target column.
 *
 * This is the step that used to break the dialog. A file with twenty-four
 * columns stacked twenty-four label+select rows into a modal that had no bound
 * of its own, so the dialog grew past the viewport. Now the list is filterable,
 * scrolls inside the step, and carries a progress count — the layout is the
 * same whether the importer has three columns or fifty.
 */
const props = defineProps<{
    columns: KinetixImportColumn[];
    headers: string[];
    mapping: Record<string, number | null>;
    /** Every column matched a header and every header was claimed. */
    isExactMatch: boolean;
    /** Source columns no target column claimed. */
    unusedHeaders: string[];
    missingRequired: KinetixImportColumn[];
}>();

const emit = defineEmits<{
    (e: 'update', column: string, value: string): void;
    (e: 'reset'): void;
}>();

const { t } = useI18n();

const search = ref('');
const unmappedOnly = ref(false);
const searchId = `kinetix-import-search-${useId()}`;

const mappedCount = computed(
    () =>
        props.columns.filter(
            (column) =>
                props.mapping[column.name] !== null &&
                props.mapping[column.name] !== undefined,
        ).length,
);

const isMapped = (column: KinetixImportColumn): boolean =>
    props.mapping[column.name] !== null &&
    props.mapping[column.name] !== undefined;

const visibleColumns = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.columns.filter((column) => {
        if (unmappedOnly.value && isMapped(column)) {
            return false;
        }

        if (term === '') {
            return true;
        }

        return (
            column.label.toLowerCase().includes(term) ||
            column.name.toLowerCase().includes(term)
        );
    });
});

/** Options for one column's select: every header, plus "not mapped". */
const mappingOptions = computed<Record<string, string>>(() => {
    const record: Record<string, string> = { '': t('kinetix.not_mapped') };

    props.headers.forEach((header, index) => {
        record[String(index)] =
            header.trim() === ''
                ? t('kinetix.import_unnamed_column', { number: index + 1 })
                : header;
    });

    return record;
});

/** Indices another column already claimed, so one source is never reused. */
const disabledKeysFor = (columnName: string): string[] => {
    const used: string[] = [];

    for (const [name, index] of Object.entries(props.mapping)) {
        if (name !== columnName && index !== null && index !== undefined) {
            used.push(String(index));
        }
    }

    return used;
};

const fieldId = (name: string): string =>
    `kinetix-import-map-${name.replace(/[^a-zA-Z0-9_-]/g, '-')}`;
</script>

<template>
    <div class="gap-4 flex flex-col">
        <!-- An exact match means nothing was guessed: every column lined up.
             Say so, and colour is never the only channel — there's an icon and
             the sentence itself. -->
        <p
            v-if="isExactMatch"
            class="px-3 py-2 gap-2 text-sm flex items-start rounded-md bg-success/10 text-success"
        >
            <CheckCircle2 class="size-4 mt-0.5 shrink-0" aria-hidden="true" />
            {{ t('kinetix.import_exact_match') }}
        </p>

        <div class="gap-3 sm:flex-row sm:items-end flex flex-col">
            <div class="gap-1.5 min-w-0 flex flex-1 flex-col">
                <KinetixLabel :for="searchId">
                    {{ t('kinetix.import_mapping_search') }}
                </KinetixLabel>
                <div class="relative">
                    <Search
                        class="size-4 top-2.5 left-3 absolute text-muted-foreground"
                        aria-hidden="true"
                    />
                    <input
                        :id="searchId"
                        v-model="search"
                        type="search"
                        :placeholder="t('kinetix.import_mapping_search')"
                        :class="[inputClass, 'pl-9']"
                    />
                </div>
            </div>

            <div class="gap-2 flex items-center">
                <KinetixButton
                    :variant="unmappedOnly ? 'default' : 'outline'"
                    :aria-pressed="unmappedOnly"
                    @click="unmappedOnly = !unmappedOnly"
                >
                    {{ t('kinetix.import_mapping_unmapped_only') }}
                </KinetixButton>
                <KinetixButton
                    variant="outline"
                    :aria-label="t('kinetix.import_mapping_reset')"
                    @click="emit('reset')"
                >
                    <template #icon>
                        <RotateCcw class="size-4" />
                    </template>
                    <span class="sm:inline hidden">
                        {{ t('kinetix.import_mapping_reset') }}
                    </span>
                </KinetixButton>
            </div>
        </div>

        <div class="gap-2 text-xs flex flex-wrap items-center justify-between">
            <span class="text-muted-foreground tabular-nums">
                {{
                    t('kinetix.import_mapping_progress', {
                        mapped: mappedCount,
                        total: columns.length,
                    })
                }}
            </span>
            <span
                v-if="missingRequired.length > 0"
                class="gap-1.5 font-medium inline-flex items-center text-destructive"
            >
                <AlertTriangle class="size-3.5" aria-hidden="true" />
                {{
                    t('kinetix.import_mapping_missing_required', {
                        count: missingRequired.length,
                    })
                }}
            </span>
        </div>

        <ul class="gap-2 flex flex-col">
            <li
                v-for="column in visibleColumns"
                :key="column.name"
                class="gap-3 sm:flex-row sm:items-center flex flex-col"
            >
                <KinetixLabel
                    :for="fieldId(column.name)"
                    class="sm:w-2/5 min-w-0 shrink-0"
                >
                    <span class="min-w-0 truncate">{{ column.label }}</span>
                    <span
                        v-if="column.isRequired"
                        class="text-destructive"
                        :aria-label="t('kinetix.import_required_field')"
                        >*</span
                    >
                </KinetixLabel>

                <ArrowRight
                    class="size-3.5 sm:block hidden shrink-0 text-muted-foreground"
                    aria-hidden="true"
                />

                <div class="min-w-0 flex-1">
                    <KinetixSelect
                        :id="fieldId(column.name)"
                        :value="mapping[column.name] ?? ''"
                        :options="mappingOptions"
                        :disabled-keys="disabledKeysFor(column.name)"
                        :aria-invalid="
                            column.isRequired && !isMapped(column)
                                ? 'true'
                                : undefined
                        "
                        @update:value="emit('update', column.name, $event)"
                    />
                </div>
            </li>
        </ul>

        <p
            v-if="visibleColumns.length === 0"
            class="py-6 text-sm text-center text-muted-foreground"
        >
            {{ t('kinetix.import_mapping_no_matches') }}
        </p>

        <!-- Unclaimed source columns are data the import will silently drop,
             so they are stated rather than left for the user to notice. -->
        <p
            v-if="unusedHeaders.length > 0"
            class="text-xs text-muted-foreground"
        >
            {{
                t('kinetix.import_unused_columns', {
                    count: unusedHeaders.length,
                    columns: unusedHeaders.slice(0, 5).join(', '),
                })
            }}
        </p>
    </div>
</template>
