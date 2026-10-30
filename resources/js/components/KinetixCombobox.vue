<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';
import { Check, ChevronsUpDown } from '@lucide/vue';
import {
    ComboboxAnchor,
    ComboboxContent,
    ComboboxEmpty,
    ComboboxInput,
    ComboboxItem,
    ComboboxItemIndicator,
    ComboboxPortal,
    ComboboxRoot,
    ComboboxTrigger,
    ComboboxViewport,
} from 'reka-ui';
import { computed, onBeforeUnmount, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixOption, KinetixSharedProps } from '@/types/kinetix';

/**
 * Searchable select built on Reka Combobox. Two modes:
 *  - local: filters the provided `options` client-side (Reka's built-in filter).
 *  - remote: when `search-token` is set, queries the Kinetix forms-search endpoint
 *    (debounced + lazy) so options come from the server; `fetch-options` plugs in
 *    any other source the same way.
 * `options` is a `value => label` map or a `[{ value, label }]` list — the list
 * keeps its order even when the values are numeric (a map's integer keys are
 * re-sorted by the browser).
 * Emits `update:value` like <KinetixSelect>, so it's a drop-in for searchable selects.
 */
const props = withDefaults(
    defineProps<{
        value?: string | number | null;
        options?: Record<string, string> | KinetixOption[] | null;
        placeholder?: string | null;
        id?: string;
        disabled?: boolean;
        searchToken?: string | null;
        /** A remote source of its own: called (debounced) with the typed term. */
        fetchOptions?: ((query: string) => Promise<KinetixOption[]>) | null;
        /** The selected value's label when it isn't among the loaded options. */
        valueLabel?: string | null;
    }>(),
    {
        value: null,
        options: () => ({}),
        placeholder: null,
        id: undefined,
        disabled: false,
        searchToken: null,
        fetchOptions: null,
        valueLabel: null,
    },
);

const emit = defineEmits<{ (e: 'update:value', value: string): void }>();

defineOptions({ inheritAttrs: false });

const { t } = useI18n();
const page = usePage<KinetixSharedProps>();

const open = ref(false);
const loading = ref(false);
const remote = computed(() => !!props.searchToken || !!props.fetchOptions);

const toList = (
    options: Record<string, string> | KinetixOption[] | null | undefined,
): KinetixOption[] =>
    Array.isArray(options)
        ? options
        : Object.entries(options ?? {}).map(([value, label]) => ({
              value,
              label,
          }));

const toMap = (list: KinetixOption[]): Record<string, string> =>
    Object.fromEntries(
        list.map((option) => [String(option.value), option.label]),
    );

const localItems = computed(() => toList(props.options));

// value → label, seeded with the initial options (incl. the selected one) and
// grown with every remote fetch, so the trigger can always show the label.
const labelMap = ref<Record<string, string>>(toMap(localItems.value));
watch(localItems, (next) => {
    labelMap.value = { ...labelMap.value, ...toMap(next) };
});

const remoteItems = ref<KinetixOption[]>([]);
const items = computed<KinetixOption[]>(() =>
    remote.value ? remoteItems.value : localItems.value,
);

const selectedValue = computed(() =>
    props.value === null || props.value === undefined
        ? ''
        : String(props.value),
);
const selectedLabel = computed(
    () =>
        labelMap.value[selectedValue.value] ??
        (selectedValue.value !== '' ? (props.valueLabel ?? '') : ''),
);

let debounce: ReturnType<typeof setTimeout> | undefined;
onBeforeUnmount(() => clearTimeout(debounce));

async function fetchRemote(query: string): Promise<void> {
    if (!props.searchToken && !props.fetchOptions) {
        return;
    }

    loading.value = true;

    try {
        if (props.fetchOptions) {
            remoteItems.value = await props.fetchOptions(query);
        } else {
            const result = await kinetixFetch<{
                options: Record<string, string>;
            }>(`/${kinetixRoutePrefix(page)}/forms/search`, {
                method: 'POST',
                body: { token: props.searchToken, q: query },
            });
            remoteItems.value = toList(result?.options);
        }

        labelMap.value = { ...labelMap.value, ...toMap(remoteItems.value) };
    } finally {
        loading.value = false;
    }
}

function onInput(event: Event): void {
    if (!remote.value) {
        return; // local mode: Reka filters by the input automatically
    }

    const query = (event.target as HTMLInputElement).value;
    clearTimeout(debounce);
    debounce = setTimeout(() => fetchRemote(query), 250);
}

// Lazy: load the first page only when a remote combobox is opened.
watch(open, (isOpen) => {
    if (isOpen && remote.value && remoteItems.value.length === 0) {
        fetchRemote('');
    }
});

// A new source (the state behind a city list changed) starts from scratch.
watch(
    () => props.fetchOptions,
    () => {
        remoteItems.value = [];
    },
);

function onSelect(value: unknown): void {
    emit(
        'update:value',
        value === null || value === undefined ? '' : String(value),
    );
    open.value = false;
}

const triggerClass =
    'flex h-9 w-full items-center justify-between gap-2 rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:border-ring focus-visible:ring-ring/50 focus-visible:ring-[3px] aria-invalid:border-destructive aria-invalid:ring-destructive/20 dark:bg-input/30 disabled:cursor-not-allowed disabled:opacity-50';
</script>

<template>
    <ComboboxRoot
        :open="open"
        :model-value="selectedValue"
        :ignore-filter="remote"
        :disabled="disabled"
        @update:open="open = $event"
        @update:model-value="onSelect"
    >
        <ComboboxAnchor as-child>
            <!-- The trigger IS the control here (the search input lives in the
                 popup), so it must reach the keyboard and carry the field's
                 name. Its primitive hard-codes tabindex="-1" and an English
                 aria-label="Show popup" that outranked the field's <label>:
                 overridden — an aria-label the caller passes still wins. -->
            <ComboboxTrigger
                :id="id"
                v-bind="$attrs"
                :aria-label="
                    ($attrs['aria-label'] as string | undefined) ?? undefined
                "
                tabindex="0"
                :class="triggerClass"
            >
                <span
                    :class="
                        selectedLabel
                            ? 'text-foreground'
                            : 'text-muted-foreground'
                    "
                >
                    {{ selectedLabel || (placeholder ?? '') }}
                </span>
                <ChevronsUpDown class="h-4 w-4 opacity-50" />
            </ComboboxTrigger>
        </ComboboxAnchor>

        <ComboboxPortal>
            <ComboboxContent
                position="popper"
                :side-offset="4"
                class="max-h-96 shadow-md data-[state=open]:animate-in data-[state=closed]:animate-out data-[state=closed]:fade-out-0 data-[state=open]:fade-in-0 data-[state=closed]:zoom-out-95 data-[state=open]:zoom-in-95 data-[side=bottom]:slide-in-from-top-2 data-[side=left]:slide-in-from-right-2 data-[side=right]:slide-in-from-left-2 data-[side=top]:slide-in-from-bottom-2 relative z-[var(--kinetix-z-popover,120)] w-(--reka-combobox-trigger-width) min-w-[8rem] origin-(--reka-combobox-content-transform-origin) overflow-hidden rounded-md border border-border bg-popover text-popover-foreground"
            >
                <div class="px-3 flex items-center border-b border-border">
                    <!-- Opens empty: Reka would otherwise prefill the search
                         with the selected VALUE (an id, a country code). -->
                    <ComboboxInput
                        :display-value="() => ''"
                        :placeholder="t('kinetix.spotlight_placeholder')"
                        class="h-9 text-sm w-full bg-transparent text-foreground outline-none placeholder:text-muted-foreground"
                        @input="onInput"
                    />
                </div>

                <ComboboxEmpty
                    class="py-6 text-sm text-center text-muted-foreground"
                >
                    {{ loading ? '…' : t('kinetix.spotlight_empty') }}
                </ComboboxEmpty>

                <ComboboxViewport class="max-h-60 p-1 overflow-y-auto">
                    <ComboboxItem
                        v-for="item in items"
                        :key="item.value"
                        :value="String(item.value)"
                        class="gap-2 rounded-sm py-1.5 pr-8 pl-2 text-sm relative flex w-full cursor-default items-center text-foreground outline-none select-none data-[highlighted]:bg-accent data-[highlighted]:text-accent-foreground"
                    >
                        <span>{{ item.label }}</span>
                        <span
                            class="right-2 size-3.5 absolute flex items-center justify-center"
                        >
                            <ComboboxItemIndicator>
                                <Check class="size-4" />
                            </ComboboxItemIndicator>
                        </span>
                    </ComboboxItem>
                </ComboboxViewport>
            </ComboboxContent>
        </ComboboxPortal>
    </ComboboxRoot>
</template>
