<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { BookOpen, LayoutGrid, List, Search } from '@lucide/vue';
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixHelp } from '@/composables/useKinetixHelp';
import { resolveIcon } from '@/composables/useKinetixIcons';
import { buttonVariants, inputClass } from '@/composables/useShadcnVariants';
import type { KinetixHelpArticleSummary } from '@/types';
import { cn } from './primitives/cn';

/**
 * The Help Center index: permission-filtered articles as grouped cards (or a
 * list — `layout` prop + user toggle) with a debounced server-side search
 * over titles AND bodies. Content comes from Kinetix's help endpoints, so a
 * user never sees articles their Gate denies.
 *
 * Navigation defaults to `{current path}/{slug}` — the scaffolded page lives
 * at `/help`, so articles land on `/help/{slug}`. Override with the
 * `article-href` prop when your routes differ.
 */
const props = withDefaults(
    defineProps<{
        /** Initial layout; the user can still toggle unless hideToggle. */
        layout?: 'grid' | 'list';
        hideToggle?: boolean;
        /** Builds the link for an article; defaults to `{current path}/{slug}`. */
        articleHref?: (slug: string) => string;
    }>(),
    { layout: 'grid', hideToggle: false, articleHref: undefined },
);

const { t } = useI18n();
const { articles, loading, results, searching, loadArticles, search } =
    useKinetixHelp();

onMounted(loadArticles);

const view = ref<'grid' | 'list'>(props.layout);

const href = (slug: string): string => {
    if (props.articleHref) {
        return props.articleHref(slug);
    }

    const path = window.location.pathname.replace(/\/+$/, '');

    return `${path}/${slug}`;
};

const open = (slug: string): void => {
    router.visit(href(slug));
};

// --- Debounced server-side search ------------------------------------------
const query = ref('');
let debounce: ReturnType<typeof setTimeout> | null = null;

const onSearchInput = (): void => {
    if (debounce) {
        clearTimeout(debounce);
    }

    debounce = setTimeout(() => void search(query.value), 250);
};

onBeforeUnmount(() => {
    if (debounce) {
        clearTimeout(debounce);
    }
});

const isSearching = computed(() => query.value.trim().length >= 2);

// --- Grouping ---------------------------------------------------------------
const groups = computed(() => {
    const map = new Map<string | null, KinetixHelpArticleSummary[]>();

    for (const entry of articles.value) {
        const key = entry.group ?? null;
        map.set(key, [...(map.get(key) ?? []), entry]);
    }

    // Named groups first (insertion order), ungrouped last.
    return [...map.entries()]
        .sort(([a], [b]) => Number(a === null) - Number(b === null))
        .map(([group, items]) => ({ group, items }));
});
</script>

<template>
    <div class="space-y-4">
        <div class="gap-3 flex flex-wrap items-center justify-between">
            <div class="min-w-0 sm:max-w-sm relative flex-1">
                <Search
                    class="size-4 left-3 absolute top-1/2 -translate-y-1/2 text-muted-foreground"
                />
                <input
                    v-model="query"
                    type="search"
                    :class="cn(inputClass, 'pl-9')"
                    :placeholder="t('kinetix.help_search_placeholder')"
                    @input="onSearchInput"
                />
            </div>

            <div v-if="!hideToggle" class="gap-1 flex items-center">
                <button
                    type="button"
                    :class="
                        buttonVariants({
                            variant: view === 'grid' ? 'secondary' : 'ghost',
                            size: 'icon-sm',
                        })
                    "
                    :title="t('kinetix.help_grid_view')"
                    :aria-pressed="view === 'grid'"
                    @click="view = 'grid'"
                >
                    <LayoutGrid class="size-4" />
                </button>
                <button
                    type="button"
                    :class="
                        buttonVariants({
                            variant: view === 'list' ? 'secondary' : 'ghost',
                            size: 'icon-sm',
                        })
                    "
                    :title="t('kinetix.help_list_view')"
                    :aria-pressed="view === 'list'"
                    @click="view = 'list'"
                >
                    <List class="size-4" />
                </button>
            </div>
        </div>

        <!-- Search results -->
        <div v-if="isSearching" class="space-y-2">
            <div v-if="searching" class="space-y-2">
                <div
                    v-for="i in 3"
                    :key="i"
                    class="h-16 animate-pulse rounded-xl bg-muted"
                ></div>
            </div>

            <p
                v-else-if="results.length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ t('kinetix.help_no_results', { query: query.trim() }) }}
            </p>

            <a
                v-for="hit in results"
                v-else
                :key="hit.slug"
                :href="href(hit.slug)"
                class="gap-1 rounded-xl p-4 flex flex-col border border-border bg-card transition-colors hover:border-primary/40 hover:bg-accent/50"
                @click.prevent="open(hit.slug)"
            >
                <span class="gap-2 flex items-center">
                    <span class="text-sm font-semibold text-foreground">{{
                        hit.title
                    }}</span>
                    <span
                        v-if="hit.group"
                        class="px-1.5 py-0.5 rounded bg-secondary text-[11px] text-secondary-foreground"
                        >{{ hit.group }}</span
                    >
                </span>
                <span class="text-xs text-muted-foreground">{{
                    hit.excerpt
                }}</span>
            </a>
        </div>

        <!-- Index -->
        <template v-else>
            <div
                v-if="loading"
                class="gap-3 sm:grid-cols-2 xl:grid-cols-3 grid"
            >
                <div
                    v-for="i in 6"
                    :key="i"
                    class="h-28 animate-pulse rounded-xl bg-muted"
                ></div>
            </div>

            <p
                v-else-if="articles.length === 0"
                class="text-sm text-muted-foreground"
            >
                {{ t('kinetix.help_no_articles') }}
            </p>

            <section
                v-for="{ group, items } in groups"
                v-else
                :key="group ?? '__ungrouped'"
                class="space-y-2"
            >
                <h3
                    v-if="group"
                    class="text-xs font-semibold tracking-wide text-muted-foreground uppercase"
                >
                    {{ group }}
                </h3>

                <!-- Card grid -->
                <div
                    v-if="view === 'grid'"
                    class="gap-3 sm:grid-cols-2 xl:grid-cols-3 grid"
                >
                    <a
                        v-for="entry in items"
                        :key="entry.slug"
                        :href="href(entry.slug)"
                        class="group gap-2 rounded-xl p-4 flex flex-col border border-border bg-card transition-colors hover:border-primary/40 hover:bg-accent/50"
                        @click.prevent="open(entry.slug)"
                    >
                        <span class="gap-2 flex items-center">
                            <span
                                class="size-8 rounded-lg inline-flex shrink-0 items-center justify-center bg-primary/10 text-primary"
                            >
                                <component
                                    :is="resolveIcon(entry.icon) ?? BookOpen"
                                    class="size-4"
                                />
                            </span>
                            <span
                                class="text-sm font-semibold truncate text-foreground"
                                >{{ entry.title }}</span
                            >
                        </span>
                        <span
                            class="text-xs line-clamp-2 text-muted-foreground"
                            >{{ entry.excerpt }}</span
                        >
                    </a>
                </div>

                <!-- List -->
                <div
                    v-else
                    class="rounded-xl divide-y divide-border border border-border bg-card"
                >
                    <a
                        v-for="entry in items"
                        :key="entry.slug"
                        :href="href(entry.slug)"
                        class="gap-3 px-4 py-3 flex items-center transition-colors hover:bg-accent/50"
                        @click.prevent="open(entry.slug)"
                    >
                        <component
                            :is="resolveIcon(entry.icon) ?? BookOpen"
                            class="size-4 shrink-0 text-primary"
                        />
                        <span class="min-w-0">
                            <span
                                class="text-sm font-medium block truncate text-foreground"
                                >{{ entry.title }}</span
                            >
                            <span
                                class="text-xs block truncate text-muted-foreground"
                                >{{ entry.excerpt }}</span
                            >
                        </span>
                    </a>
                </div>
            </section>
        </template>
    </div>
</template>
