<script setup lang="ts">
import { Download, Plus, Trash2 } from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixTableRepeater } from '@/composables/useKinetixTableRepeater';
import { buttonVariants } from '@/composables/useShadcnVariants';
import KinetixFormSchema from './KinetixFormSchema.vue';

/**
 * A Repeater rendered as a table: one row per item, one column per sub-field.
 * Reuses KinetixFormSchema (label-stripped) to render every cell, so all field
 * types work. Footer summaries + CSV export like a table. Rows live in the form
 * value (deferred); when `comp.autosave` + a descriptor are present, each
 * add/edit/delete also persists to the bound relation.
 */
const props = defineProps<{
    comp: any;
    modelValue: Record<string, any>[];
    errors: Record<string, string>;
}>();

const emit = defineEmits<{
    (e: 'update:modelValue', value: Record<string, any>[]): void;
}>();

const { t } = useI18n();
const autosaveApi = useKinetixTableRepeater();

const rows = computed<Record<string, any>[]>(() =>
    Array.isArray(props.modelValue) ? props.modelValue : [],
);

// Column field definitions (with labels for the header) and a label-stripped
// variant used to render each cell without a per-cell label/description.
const columns = computed<any[]>(() =>
    Array.isArray(props.comp.schema) ? props.comp.schema : [],
);
const cellColumns = computed<any[]>(() =>
    columns.value.map((c) => ({ ...c, label: null, description: null })),
);
const cellSchema = (col: any): any[] => [col];

const canDelete = computed(
    () => !props.comp.minItems || rows.value.length > props.comp.minItems,
);
const canAdd = computed(
    () => !props.comp.maxItems || rows.value.length < props.comp.maxItems,
);

const token = computed<string | null>(() => props.comp.autosaveToken ?? null);
const isAutosave = computed(() => !!props.comp.autosave && !!token.value);

// Debounced per-row autosave accumulator.
const pending = new Map<number | string, Record<string, unknown>>();
const timers = new Map<number | string, ReturnType<typeof setTimeout>>();

function flush(id: number | string): void {
    const values = pending.get(id);
    pending.delete(id);
    timers.delete(id);

    if (values && token.value) {
        void autosaveApi.update(token.value, id, values);
    }
}

function queueUpdate(id: number | string, field: string, value: unknown): void {
    pending.set(id, { ...(pending.get(id) ?? {}), [field]: value });

    const existing = timers.get(id);
    if (existing) {
        clearTimeout(existing);
    }

    timers.set(
        id,
        setTimeout(() => flush(id), 500),
    );
}

function buildBlankRow(): Record<string, any> {
    const row: Record<string, any> = {};
    for (const col of columns.value) {
        if (col.name) {
            row[col.name] = col.defaultValue ?? null;
        }
    }

    return row;
}

function updateCell(index: number, field: string, value: any): void {
    const next = [...rows.value];
    next[index] = { ...next[index], [field]: value };
    emit('update:modelValue', next);

    if (isAutosave.value && next[index].id != null) {
        queueUpdate(next[index].id, field, value);
    }
}

async function addRow(): Promise<void> {
    const row = buildBlankRow();

    if (isAutosave.value && token.value) {
        const id = await autosaveApi.create(token.value, row);
        if (id != null) {
            row.id = id;
        }
    }

    emit('update:modelValue', [...rows.value, row]);
}

function removeRow(index: number): void {
    const row = rows.value[index];
    const next = [...rows.value];
    next.splice(index, 1);
    emit('update:modelValue', next);

    if (isAutosave.value && row?.id != null && token.value) {
        void autosaveApi.remove(token.value, row.id);
    }
}

// --- Summaries (footer aggregates) ------------------------------------------
const summarize = computed<Record<string, string>>(
    () => props.comp.summarize ?? {},
);
const hasSummary = computed(() => Object.keys(summarize.value).length > 0);

function summaryFor(field: string): string {
    const agg = summarize.value[field];
    if (!agg) {
        return '';
    }

    const nums = rows.value
        .map((r) => Number(r[field]))
        .filter((n) => !Number.isNaN(n));

    if (agg === 'count') {
        return String(rows.value.length);
    }
    if (nums.length === 0) {
        return '—';
    }

    const sum = nums.reduce((a, b) => a + b, 0);
    const value =
        agg === 'sum'
            ? sum
            : agg === 'avg'
              ? sum / nums.length
              : agg === 'min'
                ? Math.min(...nums)
                : agg === 'max'
                  ? Math.max(...nums)
                  : sum;

    return String(Math.round(value * 100) / 100);
}

// --- CSV export --------------------------------------------------------------
function exportCsv(): void {
    const headers = columns.value.map((c) => c.label ?? c.name);
    const names = columns.value.map((c) => c.name);
    const escape = (v: unknown): string => {
        const s = v == null ? '' : String(v);
        return /[",\n]/.test(s) ? `"${s.replace(/"/g, '""')}"` : s;
    };

    const lines = [
        headers.map(escape).join(','),
        ...rows.value.map((r) => names.map((n) => escape(r[n])).join(',')),
    ];

    const blob = new Blob([lines.join('\n')], {
        type: 'text/csv;charset=utf-8;',
    });
    const link = document.createElement('a');
    link.href = URL.createObjectURL(blob);
    link.download = `${props.comp.name ?? 'rows'}.csv`;
    link.click();
    URL.revokeObjectURL(link.href);
}
</script>

<template>
    <div class="space-y-3">
        <div class="rounded-lg overflow-x-auto border border-input">
            <table class="text-sm w-full">
                <thead>
                    <tr class="border-b border-input bg-muted/50">
                        <th
                            v-for="col in columns"
                            :key="col.name"
                            class="px-3 py-2 text-xs font-medium text-left text-muted-foreground"
                        >
                            {{ col.label }}
                        </th>
                        <th class="w-10 px-2 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, idx) in rows"
                        :key="row.id ?? idx"
                        class="border-b border-input last:border-0"
                    >
                        <td
                            v-for="(col, ci) in cellColumns"
                            :key="col.name"
                            class="px-2 py-1.5 align-top"
                        >
                            <KinetixFormSchema
                                :schema="cellSchema(col)"
                                :values="row"
                                :errors="errors"
                                @update:value="
                                    (name, val) => updateCell(idx, name, val)
                                "
                            />
                        </td>
                        <td class="px-2 py-1.5 text-right align-middle">
                            <button
                                type="button"
                                class="size-7 inline-flex items-center justify-center rounded-md text-muted-foreground transition-colors hover:bg-destructive/10 hover:text-destructive disabled:opacity-30"
                                :disabled="!canDelete"
                                :aria-label="t('kinetix.remove')"
                                @click="removeRow(idx)"
                            >
                                <Trash2 class="size-4" />
                            </button>
                        </td>
                    </tr>
                    <tr v-if="rows.length === 0">
                        <td
                            :colspan="columns.length + 1"
                            class="px-3 py-6 text-sm text-center text-muted-foreground"
                        >
                            {{ t('kinetix.table_repeater_empty') }}
                        </td>
                    </tr>
                </tbody>
                <tfoot v-if="hasSummary && rows.length">
                    <tr class="font-medium border-t border-input bg-muted/30">
                        <td
                            v-for="col in columns"
                            :key="col.name"
                            class="px-3 py-2 text-xs"
                        >
                            {{ summaryFor(col.name) }}
                        </td>
                        <td></td>
                    </tr>
                </tfoot>
            </table>
        </div>

        <div class="flex items-center justify-between">
            <button
                type="button"
                :class="buttonVariants({ variant: 'outline', size: 'sm' })"
                :disabled="!canAdd"
                @click="addRow"
            >
                <Plus class="size-4" />
                {{ comp.addActionLabel ?? t('kinetix.add_item') }}
            </button>

            <button
                v-if="comp.exportable && rows.length"
                type="button"
                :class="buttonVariants({ variant: 'ghost', size: 'sm' })"
                @click="exportCsv"
            >
                <Download class="size-4" />
                {{ t('kinetix.export') }}
            </button>
        </div>
    </div>
</template>
