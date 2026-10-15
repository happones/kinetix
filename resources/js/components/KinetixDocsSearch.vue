<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { Search } from '@lucide/vue';
import {
    computed,
    nextTick,
    onBeforeUnmount,
    onMounted,
    ref,
    watch,
} from 'vue';
import { useI18n } from 'vue-i18n';
import {
    searchKinetixDocs,
    useKinetixDocsSearch,
} from '@/composables/useKinetixDocsSearch';
import KinetixButton from './KinetixButton.vue';
import KinetixModal from './primitives/KinetixModal.vue';

/**
 * The docs search: a button that opens a search dialog (also on `/`). The
 * index is fetched from `url` on first open; typing ranks pages by title,
 * headings and text, and the arrow keys + Enter pick a result (a combobox
 * over a listbox).
 */
const props = defineProps<{
    url: string;
}>();

const { t } = useI18n();
const { entries, loading, failed, load } = useKinetixDocsSearch();

const open = ref(false);
const query = ref('');
const active = ref(0);
const input = ref<HTMLInputElement | null>(null);

const results = computed(() => searchKinetixDocs(entries.value, query.value));
const optionId = (index: number): string => `kx-docs-result-${index}`;

watch(results, () => {
    active.value = 0;
});

async function show(): Promise<void> {
    open.value = true;
    void load(props.url);
    await nextTick();
    input.value?.focus();
}

function go(index: number): void {
    const result = results.value[index];

    if (!result) {
        return;
    }

    open.value = false;
    router.visit(result.url);
}

function onKeydown(event: KeyboardEvent): void {
    const count = results.value.length;

    if (event.key === 'ArrowDown' && count) {
        event.preventDefault();
        active.value = (active.value + 1) % count;
    } else if (event.key === 'ArrowUp' && count) {
        event.preventDefault();
        active.value = (active.value - 1 + count) % count;
    } else if (event.key === 'Enter') {
        event.preventDefault();
        go(active.value);
    }
}

watch(active, async (index) => {
    await nextTick();
    document
        .getElementById(optionId(index))
        ?.scrollIntoView({ block: 'nearest' });
});

/** `/` opens the search, unless the reader is typing somewhere. */
function onGlobalKeydown(event: KeyboardEvent): void {
    // The target is the window itself when nothing has focus.
    const target = event.target instanceof Element ? event.target : null;

    if (
        event.key !== '/' ||
        open.value ||
        event.metaKey ||
        event.ctrlKey ||
        event.altKey ||
        target?.closest('input, textarea, select, [contenteditable="true"]')
    ) {
        return;
    }

    event.preventDefault();
    void show();
}

onMounted(() => window.addEventListener('keydown', onGlobalKeydown));
onBeforeUnmount(() => window.removeEventListener('keydown', onGlobalKeydown));
</script>

<template>
    <div>
        <KinetixButton
            variant="outline"
            class="font-normal sm:w-64 min-w-0 w-full justify-start overflow-hidden text-muted-foreground"
            :aria-label="t('kinetix.docs_search')"
            @click="show"
        >
            <template #icon><Search /></template>
            <span class="min-w-0 flex-1 truncate text-left">{{
                t('kinetix.docs_search')
            }}</span>
            <kbd
                class="px-1.5 text-xs sm:inline rounded font-mono hidden shrink-0 border border-border"
                aria-hidden="true"
                >/</kbd
            >
        </KinetixButton>

        <KinetixModal
            v-model:open="open"
            :title="t('kinetix.docs_search')"
            placement="top"
            max-width="sm:max-w-xl"
        >
            <div class="space-y-3">
                <input
                    ref="input"
                    v-model="query"
                    type="search"
                    role="combobox"
                    aria-autocomplete="list"
                    aria-controls="kx-docs-results"
                    :aria-expanded="results.length > 0"
                    :aria-activedescendant="
                        results.length ? optionId(active) : undefined
                    "
                    :aria-label="t('kinetix.docs_search')"
                    :placeholder="t('kinetix.docs_search_placeholder')"
                    class="h-10 px-3 text-base md:text-sm w-full rounded-md border border-input bg-transparent outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                    @keydown="onKeydown"
                />

                <p
                    v-if="loading && !entries.length"
                    class="text-sm text-muted-foreground"
                >
                    {{ t('kinetix.docs_loading') }}
                </p>
                <p v-else-if="failed" class="text-sm text-destructive">
                    {{ t('kinetix.docs_search_failed') }}
                </p>
                <p
                    v-else-if="query.trim() && !results.length"
                    class="text-sm text-muted-foreground"
                    role="status"
                >
                    {{
                        t('kinetix.docs_search_empty', { query: query.trim() })
                    }}
                </p>

                <ul
                    id="kx-docs-results"
                    role="listbox"
                    :aria-label="t('kinetix.docs_search')"
                    class="space-y-1 max-h-[60vh] overflow-y-auto"
                >
                    <li
                        v-for="(result, index) in results"
                        :id="optionId(index)"
                        :key="result.key"
                        role="option"
                        :aria-selected="index === active"
                        :class="[
                            'px-3 py-2 cursor-pointer rounded-md',
                            index === active
                                ? 'bg-accent'
                                : 'hover:bg-accent/60',
                        ]"
                        @click="go(index)"
                        @mousemove="active = index"
                    >
                        <span class="text-sm font-medium block text-foreground">
                            {{ result.title }}
                        </span>
                        <span
                            v-if="result.context"
                            class="text-xs block text-muted-foreground"
                        >
                            {{ result.context }}
                        </span>
                        <span
                            class="mt-1 text-xs line-clamp-2 block [overflow-wrap:anywhere] text-muted-foreground"
                        >
                            <template
                                v-for="(part, partIndex) in result.excerpt"
                                :key="partIndex"
                            >
                                <mark
                                    v-if="part.match"
                                    class="rounded-sm bg-primary/15 text-foreground"
                                    >{{ part.text }}</mark
                                >
                                <template v-else>{{ part.text }}</template>
                            </template>
                        </span>
                    </li>
                </ul>
            </div>
        </KinetixModal>
    </div>
</template>
