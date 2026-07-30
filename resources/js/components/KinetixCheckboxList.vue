<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixSharedProps } from '@/types/kinetix';
import KinetixCheckbox from './KinetixCheckbox.vue';

const props = withDefaults(
    defineProps<{
        value?: Array<string | number> | null;
        options?: Record<string, string> | null;
        disabled?: boolean;
        inline?: boolean;
        searchable?: boolean;
        searchToken?: string | null;
    }>(),
    {
        value: () => [],
        options: () => ({}),
        disabled: false,
        inline: false,
        searchable: false,
        searchToken: null,
    },
);

const emit = defineEmits<{
    (e: 'update:value', value: Array<string | number>): void;
}>();

const page = usePage<KinetixSharedProps>();

const searchQuery = ref('');
const loading = ref(false);
const remote = computed(() => !!props.searchToken);

const remoteItems = ref<Record<string, string>>({});
const labelMap = ref<Record<string, string>>({ ...(props.options ?? {}) });

watch(
    () => props.options,
    (next) => {
        labelMap.value = { ...labelMap.value, ...(next ?? {}) };
    },
    { immediate: true },
);

const baseItems = computed<Record<string, string>>(() =>
    remote.value ? remoteItems.value : (props.options ?? {}),
);

// Filter items locally if not remote
const filteredItems = computed<Record<string, string>>(() => {
    const q = searchQuery.value.trim().toLowerCase();

    if (!q || remote.value) {
        return baseItems.value;
    }

    const res: Record<string, string> = {};

    for (const [val, lbl] of Object.entries(baseItems.value)) {
        if (lbl.toLowerCase().includes(q)) {
            res[val] = lbl;
        }
    }

    return res;
});

// Keep selected items visible even if they do not match the current search query
const itemsToRender = computed<Array<{ value: string; label: string }>>(() => {
    const list: Array<{ value: string; label: string }> = [];
    const seen = new Set<string>();

    // 1. Add all items matching search query
    for (const [val, lbl] of Object.entries(filteredItems.value)) {
        list.push({ value: val, label: lbl });
        seen.add(val);
    }

    // 2. Add currently selected items that were filtered out
    const selectedList = props.value ?? [];

    for (const val of selectedList) {
        const valStr = String(val);

        if (!seen.has(valStr)) {
            const label = labelMap.value[valStr] ?? valStr;
            list.push({ value: valStr, label });
            seen.add(valStr);
        }
    }

    return list;
});

let debounce: ReturnType<typeof setTimeout> | undefined;
onBeforeUnmount(() => clearTimeout(debounce));

async function fetchRemote(query: string): Promise<void> {
    if (!props.searchToken) {
        return;
    }

    loading.value = true;

    try {
        const result = await kinetixFetch<{ options: Record<string, string> }>(
            `/${kinetixRoutePrefix(page)}/forms/search`,
            { method: 'POST', body: { token: props.searchToken, q: query } },
        );
        remoteItems.value = result?.options ?? {};
        labelMap.value = { ...labelMap.value, ...remoteItems.value };
    } finally {
        loading.value = false;
    }
}

function onSearchInput(event: Event): void {
    const query = (event.target as HTMLInputElement).value;
    searchQuery.value = query;

    if (!remote.value) {
        return;
    }

    clearTimeout(debounce);
    debounce = setTimeout(() => fetchRemote(query), 250);
}

// Initial fetch for remote items
watch(
    remote,
    (isRemote) => {
        if (isRemote && Object.keys(remoteItems.value).length === 0) {
            fetchRemote('');
        }
    },
    { immediate: true },
);

// Membership as a Set of stringified values → O(1) per option instead of an
// O(selected) scan on every rendered checkbox (this list can be long).
const selectedSet = computed<Set<string>>(
    () => new Set((props.value ?? []).map((v) => String(v))),
);

function isChecked(val: string): boolean {
    return selectedSet.value.has(val);
}

function toggleValue(val: string, checked: boolean): void {
    const current = [...(props.value ?? [])];
    const index = current.findIndex((v) => String(v) === val);

    if (checked && index === -1) {
        current.push(val);
    } else if (!checked && index !== -1) {
        current.splice(index, 1);
    }

    emit('update:value', current);
}
</script>

<template>
    <div class="gap-2 flex flex-col">
        <!-- Search Input -->
        <div v-if="searchable" class="relative">
            <input
                type="text"
                class="h-8 px-3 py-1 text-xs shadow-xs w-full rounded-md border border-input bg-transparent focus-visible:ring-1 focus-visible:ring-ring focus-visible:outline-none dark:bg-input/30"
                placeholder="Search..."
                :value="searchQuery"
                :disabled="disabled"
                @input="onSearchInput"
            />
            <span
                v-if="loading"
                class="right-2.5 animate-pulse absolute top-1/2 -translate-y-1/2 text-[10px] text-muted-foreground"
            >
                Loading...
            </span>
        </div>

        <!-- Checkbox list items -->
        <div
            class="gap-1.5 flex"
            :class="
                inline
                    ? 'gap-4 flex-wrap items-center'
                    : 'max-h-44 pr-1 flex-col overflow-y-auto'
            "
        >
            <label
                v-for="item in itemsToRender"
                :key="item.value"
                class="gap-2 text-xs flex items-center text-foreground select-none"
                :class="
                    disabled
                        ? 'cursor-not-allowed opacity-50'
                        : 'cursor-pointer'
                "
            >
                <KinetixCheckbox
                    :checked="isChecked(item.value)"
                    :disabled="disabled"
                    @change="toggleValue(item.value, $event)"
                />
                {{ item.label }}
            </label>

            <span
                v-if="itemsToRender.length === 0"
                class="text-xs text-muted-foreground"
            >
                No options found.
            </span>
        </div>
    </div>
</template>
