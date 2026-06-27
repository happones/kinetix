<script setup lang="ts">
import { Plus, Trash2 } from '@lucide/vue';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { buttonVariants, inputClass } from '@/composables/useShadcnVariants';
import { cn } from './primitives/cn';

interface Pair {
    key: string;
    value: string;
}

const props = withDefaults(
    defineProps<{
        value?: Record<string, string> | null;
        disabled?: boolean;
    }>(),
    {
        value: null,
        disabled: false,
    },
);

const emit = defineEmits<{
    (e: 'update:value', value: Record<string, string>): void;
}>();

const { t } = useI18n();

const toRows = (source: Record<string, string> | null | undefined): Pair[] => {
    const entries = Object.entries(source ?? {}).map(([key, value]) => ({
        key,
        value: String(value),
    }));

    return entries.length > 0 ? entries : [{ key: '', value: '' }];
};

const toObject = (rows: Pair[]): Record<string, string> => {
    const result: Record<string, string> = {};

    for (const row of rows) {
        if (row.key !== '') {
            result[row.key] = row.value;
        }
    }

    return result;
};

const rows = ref<Pair[]>(toRows(props.value));

// Resync from the parent only when the external value genuinely differs from
// what we already hold — guarding against an emit → prop → watch feedback loop.
watch(
    () => props.value,
    (next) => {
        if (
            JSON.stringify(next ?? {}) !== JSON.stringify(toObject(rows.value))
        ) {
            rows.value = toRows(next);
        }
    },
);

const commit = () => {
    emit('update:value', toObject(rows.value));
};

const addRow = () => {
    rows.value.push({ key: '', value: '' });
};

const removeRow = (index: number) => {
    rows.value.splice(index, 1);

    if (rows.value.length === 0) {
        rows.value.push({ key: '', value: '' });
    }

    commit();
};
</script>

<template>
    <div class="space-y-2">
        <div
            v-for="(row, index) in rows"
            :key="index"
            class="gap-2 flex items-center"
        >
            <div class="w-1/3">
                <input
                    v-model="row.key"
                    type="text"
                    :disabled="disabled"
                    :placeholder="t('kinetix.key')"
                    :class="inputClass"
                    @input="commit"
                />
            </div>
            <div class="flex-1">
                <input
                    v-model="row.value"
                    type="text"
                    :disabled="disabled"
                    :placeholder="t('kinetix.value')"
                    :class="inputClass"
                    @input="commit"
                />
            </div>
            <button
                v-if="!disabled"
                type="button"
                :class="
                    cn(
                        buttonVariants({ variant: 'outline', size: 'icon' }),
                        'shrink-0 hover:border-destructive/40 hover:text-destructive',
                    )
                "
                @click="removeRow(index)"
            >
                <Trash2 class="h-4 w-4" />
            </button>
        </div>

        <button
            v-if="!disabled"
            type="button"
            class="gap-1.5 px-3 py-1.5 text-xs font-medium inline-flex items-center rounded-md border border-dashed border-input text-muted-foreground transition-colors hover:bg-accent"
            @click="addRow"
        >
            <Plus class="h-3.5 w-3.5" />
            {{ t('kinetix.add_row') }}
        </button>
    </div>
</template>
