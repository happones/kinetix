<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Loader2 } from '@lucide/vue';
import { computed, onBeforeUnmount, ref, useId, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { kinetixOptionList } from '@/composables/kinetixOptions';
import type { KinetixOptions } from '@/composables/kinetixOptions';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import {
    useKinetixSearch,
    useKinetixSearchAnnouncer,
} from '@/composables/useKinetixSearch';
import { useKinetixSearchLimits } from '@/composables/useKinetixSearchLimits';
import type {
    KinetixLookupMeta,
    KinetixOption,
    KinetixSharedProps,
} from '@/types/kinetix';
import KinetixCheckbox from './KinetixCheckbox.vue';

const props = withDefaults(
    defineProps<{
        value?: Array<string | number> | null;
        /** value → label, or a list when the order matters. */
        options?: KinetixOptions;
        disabled?: boolean;
        inline?: boolean;
        searchable?: boolean;
        searchToken?: string | null;
        /** A "Select all" checkbox over the options in view. */
        bulkToggleable?: boolean;
    }>(),
    {
        value: () => [],
        options: () => ({}),
        disabled: false,
        inline: false,
        searchable: false,
        searchToken: null,
        bulkToggleable: false,
    },
);

const emit = defineEmits<{
    (e: 'update:value', value: Array<string | number>): void;
}>();

const page = usePage<KinetixSharedProps>();
const { t } = useI18n();

const searchQuery = ref('');
const limits = useKinetixSearchLimits();
const hintId = `kinetix-checkbox-list-hint-${useId()}`;
const remote = computed(() => !!props.searchToken);

const search = useKinetixSearch<KinetixOption[]>({
    fetcher: async (query, { signal }) => {
        const result = await kinetixFetch<{
            options: KinetixOptions;
            meta?: KinetixLookupMeta;
        }>(`/${kinetixRoutePrefix(page)}/forms/search`, {
            method: 'POST',
            body: { token: props.searchToken, q: query },
            signal,
        });

        return {
            value: kinetixOptionList(result?.options),
            meta: result?.meta,
        };
    },
    minChars: () => limits.minChars.value,
    // Route prefix (team segment) + endpoint + signed token: never across teams.
    cacheKey: () =>
        props.searchToken
            ? `${kinetixRoutePrefix(page)}:forms.search:${props.searchToken}`
            : null,
    onSettled: useKinetixSearchAnnouncer<KinetixOption[]>({
        count: (list) => list.length,
        tooShort: false,
    }),
});

const loading = search.loading;
const remoteItems = computed<KinetixOption[]>(() => search.results.value ?? []);
const hintCount = computed<number | null>(() =>
    search.status.value === 'tooShort'
        ? limits.hintCount(search.meta.value)
        : null,
);
// A throttle that is out of retries is a failure the user has to hear about.
const remoteError = computed<string | null>(() =>
    search.status.value === 'error' ||
    search.status.value === 'expired' ||
    (search.status.value === 'throttled' && !search.retrying.value)
        ? (search.message.value ?? t('kinetix.action_failed'))
        : null,
);
const statusNote = computed<string | null>(() =>
    search.status.value === 'throttled' && search.retrying.value
        ? t('kinetix.search_throttled_retrying')
        : search.status.value === 'timedOut'
          ? t('kinetix.search_timed_out')
          : null,
);

const labelMap = ref<Record<string, string>>({});

const remember = (list: KinetixOption[]): void => {
    const next = { ...labelMap.value };

    list.forEach((option) => (next[option.value] = option.label));
    labelMap.value = next;
};

const localItems = computed(() => kinetixOptionList(props.options));

watch(localItems, remember, { immediate: true });
watch(remoteItems, remember);

const baseItems = computed<KinetixOption[]>(() =>
    remote.value ? remoteItems.value : localItems.value,
);

// Filter items locally if not remote
const filteredItems = computed<KinetixOption[]>(() => {
    const q = searchQuery.value.trim().toLowerCase();

    if (!q || remote.value) {
        return baseItems.value;
    }

    return baseItems.value.filter((option) =>
        option.label.toLowerCase().includes(q),
    );
});

// Keep selected items visible even if they do not match the current search query
const itemsToRender = computed<Array<{ value: string; label: string }>>(() => {
    const list: Array<{ value: string; label: string }> = [];
    const seen = new Set<string>();

    // 1. Add all items matching search query
    for (const option of filteredItems.value) {
        list.push(option);
        seen.add(option.value);
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

onBeforeUnmount(() => search.cancel());

function onSearchInput(event: Event): void {
    const query = (event.target as HTMLInputElement).value;
    searchQuery.value = query;

    if (!remote.value) {
        return;
    }

    void search.search(query);
}

// Initial fetch for remote items
watch(
    remote,
    (isRemote) => {
        if (isRemote && remoteItems.value.length === 0) {
            // The first page loads with the list: nothing to announce.
            void search.search('', { immediate: true, silent: true });
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

/**
 * "Select all" acts on the options in view — all of them, or the ones the
 * search left — and leaves any other selection as it is.
 */
const inView = computed<string[]>(() =>
    filteredItems.value.map((option) => option.value),
);
const selectedInView = computed(
    () => inView.value.filter((val) => selectedSet.value.has(val)).length,
);
const allInView = computed(
    () =>
        inView.value.length > 0 && selectedInView.value === inView.value.length,
);

function toggleAll(checked: boolean): void {
    const visible = new Set(inView.value);
    const kept = (props.value ?? []).filter((v) => !visible.has(String(v)));

    emit('update:value', checked ? [...kept, ...inView.value] : kept);
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
                :placeholder="t('kinetix.checkbox_list_search')"
                :aria-label="t('kinetix.checkbox_list_search')"
                :value="searchQuery"
                :disabled="disabled"
                :maxlength="limits.maxLength.value"
                :aria-describedby="hintId"
                @input="onSearchInput"
            />
            <span
                v-if="loading"
                class="right-2.5 gap-1 absolute top-1/2 flex -translate-y-1/2 items-center text-[10px] text-muted-foreground"
            >
                <Loader2
                    aria-hidden="true"
                    class="size-3 animate-spin motion-reduce:animate-none"
                />
                {{ t('kinetix.checkbox_list_loading') }}
            </span>
        </div>

        <!-- Polite hint (not an error): the term is below the minimum. -->
        <p
            v-if="searchable"
            :id="hintId"
            role="status"
            class="text-xs text-muted-foreground"
            :class="{ 'sr-only': hintCount === null }"
        >
            <template v-if="hintCount !== null">
                {{ t('kinetix.search_min_chars', { count: hintCount }) }}
            </template>
        </p>

        <!-- Visual only: the announcer tells screen readers. -->
        <p
            v-if="statusNote"
            aria-hidden="true"
            class="text-xs text-muted-foreground"
        >
            {{ statusNote }}
        </p>

        <p
            v-if="remoteError && !loading"
            role="alert"
            class="text-xs text-destructive"
        >
            {{ remoteError }}
        </p>

        <!-- Select all: checked when every option in view is, a dash when
             some are; a click on the dash selects them all. -->
        <label
            v-if="bulkToggleable && inView.length > 0"
            class="gap-2 text-xs font-medium min-h-6 pb-2 flex items-center border-b border-border text-foreground select-none"
            :class="
                disabled ? 'cursor-not-allowed opacity-50' : 'cursor-pointer'
            "
        >
            <KinetixCheckbox
                :checked="allInView"
                :indeterminate="selectedInView > 0"
                :disabled="disabled"
                @change="toggleAll"
            />
            {{ t('kinetix.select_all') }}
            <span class="font-normal text-muted-foreground tabular-nums">
                {{ selectedInView }}/{{ inView.length }}
            </span>
        </label>

        <!-- Checkbox list items -->
        <div
            class="gap-1.5 flex"
            :aria-busy="loading"
            :class="
                inline
                    ? 'gap-4 flex-wrap items-center'
                    : 'max-h-44 pr-1 flex-col overflow-y-auto'
            "
        >
            <label
                v-for="item in itemsToRender"
                :key="item.value"
                class="gap-2 text-xs min-h-6 flex items-center text-foreground select-none"
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
                {{ t('kinetix.checkbox_list_empty') }}
            </span>
        </div>
    </div>
</template>
