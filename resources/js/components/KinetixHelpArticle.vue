<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, List } from '@lucide/vue';
import { nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixHelp } from '@/composables/useKinetixHelp';
import { useKinetixHelpToc } from '@/composables/useKinetixHelpToc';

/**
 * A rendered Help Center article: server-sanitized HTML (permission-gated
 * blocks already stripped), an "on this page" TOC with scroll tracking, and
 * prev/next navigation. Internal links inside the article body are routed
 * through Inertia so cross-article markdown links stay client-side.
 *
 * Links default to `{index}/{slug}` where `{index}` is the current path minus
 * the trailing slug — matching the scaffolded `/help` + `/help/{article}`
 * routes. Override with `article-href` / `index-href` when your routes differ.
 */
const props = withDefaults(
    defineProps<{
        slug: string;
        articleHref?: (slug: string) => string;
        indexHref?: string;
    }>(),
    { articleHref: undefined, indexHref: undefined },
);

const { t } = useI18n();
const { article, articleLoading, articleError, loadArticle } = useKinetixHelp();

const contentEl = ref<HTMLElement | null>(null);
const { toc, activeId, build, scrollTo } = useKinetixHelpToc(contentEl);

const indexPath = (): string =>
    props.indexHref ??
    window.location.pathname.replace(/\/+$/, '').replace(/\/[^/]+$/, '');

const href = (slug: string): string =>
    props.articleHref ? props.articleHref(slug) : `${indexPath()}/${slug}`;

async function load(): Promise<void> {
    await loadArticle(props.slug);
    await nextTick();
    build();
}

onMounted(load);
watch(() => props.slug, load);

/**
 * Route article-body anchors through Inertia when they point at this app
 * (absolute http(s) links and #fragments keep native behavior).
 */
const onContentClick = (event: MouseEvent): void => {
    const anchor = (event.target as HTMLElement).closest('a');

    if (!anchor) {
        return;
    }

    const target = anchor.getAttribute('href') ?? '';

    if (
        target.startsWith('/') &&
        !anchor.target &&
        !event.metaKey &&
        !event.ctrlKey
    ) {
        event.preventDefault();
        router.visit(target);
    }
};
</script>

<template>
    <div class="gap-8 mx-auto flex w-full max-w-[96rem]">
        <!-- The article -->
        <div class="min-w-0 flex-1">
            <!-- Mobile: on-this-page (below xl, when the right rail is hidden) -->
            <details
                v-if="toc.length"
                class="mb-6 rounded-lg xl:hidden border border-border bg-card"
            >
                <summary
                    class="gap-2 px-4 py-2.5 text-sm font-medium flex cursor-pointer items-center select-none"
                >
                    <List class="size-4 text-muted-foreground" />
                    {{ t('kinetix.help_on_this_page') }}
                </summary>
                <nav class="space-y-0.5 p-2 border-t border-border">
                    <a
                        v-for="entry in toc"
                        :key="entry.id"
                        :href="`#${entry.id}`"
                        :class="[
                            'px-2 py-1 text-sm block rounded-md text-muted-foreground transition-colors hover:text-foreground',
                            entry.level === 3 && 'pl-5',
                        ]"
                        @click.prevent="scrollTo(entry.id)"
                    >
                        {{ entry.text }}
                    </a>
                </nav>
            </details>

            <div v-if="articleLoading" class="space-y-4 max-w-3xl mx-auto">
                <div class="h-8 animate-pulse w-2/3 rounded-md bg-muted"></div>
                <div class="h-4 animate-pulse rounded-md bg-muted"></div>
                <div class="h-4 animate-pulse w-5/6 rounded-md bg-muted"></div>
                <div class="h-64 animate-pulse rounded-xl bg-muted"></div>
            </div>

            <p
                v-else-if="articleError"
                class="text-sm max-w-3xl mx-auto text-muted-foreground"
            >
                {{ t('kinetix.help_not_found') }}
            </p>

            <template v-else-if="article">
                <!-- eslint-disable-next-line vue/no-v-html -- server-sanitized markdown -->
                <article
                    ref="contentEl"
                    class="kinetix-help-content max-w-3xl mx-auto"
                    v-html="article.html"
                    @click="onContentClick"
                />

                <!-- Prev / next navigation -->
                <nav
                    v-if="article.prev || article.next"
                    class="mt-12 gap-3 pt-6 sm:flex-row max-w-3xl mx-auto flex flex-col border-t border-border"
                >
                    <a
                        v-if="article.prev"
                        :href="href(article.prev.slug)"
                        class="group gap-3 rounded-xl p-4 flex flex-1 items-center border border-border transition-colors hover:border-primary/40 hover:bg-accent"
                        @click.prevent="router.visit(href(article.prev.slug))"
                    >
                        <ChevronLeft
                            class="size-5 group-hover:-translate-x-0.5 shrink-0 text-muted-foreground transition-transform"
                        />
                        <span class="min-w-0">
                            <span class="text-xs block text-muted-foreground">{{
                                t('kinetix.help_previous')
                            }}</span>
                            <span class="font-medium block truncate">{{
                                article.prev.title
                            }}</span>
                        </span>
                    </a>
                    <a
                        v-if="article.next"
                        :href="href(article.next.slug)"
                        class="group gap-3 rounded-xl p-4 flex flex-1 items-center justify-end border border-border text-right transition-colors hover:border-primary/40 hover:bg-accent"
                        @click.prevent="router.visit(href(article.next.slug))"
                    >
                        <span class="min-w-0">
                            <span class="text-xs block text-muted-foreground">{{
                                t('kinetix.help_next')
                            }}</span>
                            <span class="font-medium block truncate">{{
                                article.next.title
                            }}</span>
                        </span>
                        <ChevronRight
                            class="size-5 group-hover:translate-x-0.5 shrink-0 text-muted-foreground transition-transform"
                        />
                    </a>
                </nav>
            </template>
        </div>

        <!-- Right: on this page (xl+) -->
        <aside v-if="toc.length" class="w-56 xl:block hidden shrink-0">
            <div class="top-4 sticky max-h-[calc(100vh-2rem)] overflow-y-auto">
                <p
                    class="mb-2 gap-1.5 px-2 text-xs font-semibold tracking-wide flex items-center text-muted-foreground uppercase"
                >
                    <List class="size-3.5" />
                    {{ t('kinetix.help_on_this_page') }}
                </p>
                <nav class="space-y-0.5 border-l border-border">
                    <a
                        v-for="entry in toc"
                        :key="entry.id"
                        :href="`#${entry.id}`"
                        :class="[
                            'py-1 text-sm -ml-px block border-l-2 transition-colors',
                            entry.level === 3 ? 'pl-5' : 'pl-3',
                            activeId === entry.id
                                ? 'font-medium border-primary text-foreground'
                                : 'border-transparent text-muted-foreground hover:border-border hover:text-foreground',
                        ]"
                        @click.prevent="scrollTo(entry.id)"
                    >
                        {{ entry.text }}
                    </a>
                </nav>
            </div>
        </aside>
    </div>
</template>

<style scoped>
.kinetix-help-content :deep(h1) {
    font-size: 1.875rem;
    font-weight: 700;
    letter-spacing: -0.02em;
    margin-bottom: 1rem;
    scroll-margin-top: 5rem;
}
.kinetix-help-content :deep(h2) {
    font-size: 1.3rem;
    font-weight: 600;
    letter-spacing: -0.01em;
    margin-top: 2.25rem;
    margin-bottom: 0.6rem;
    padding-bottom: 0.4rem;
    border-bottom: 1px solid var(--border);
    scroll-margin-top: 5rem;
}
.kinetix-help-content :deep(h3) {
    font-size: 1.05rem;
    font-weight: 600;
    margin-top: 1.75rem;
    margin-bottom: 0.4rem;
    scroll-margin-top: 5rem;
}
.kinetix-help-content :deep(p),
.kinetix-help-content :deep(ul),
.kinetix-help-content :deep(ol) {
    margin-bottom: 1rem;
    line-height: 1.7;
    color: var(--muted-foreground);
}
.kinetix-help-content :deep(ul),
.kinetix-help-content :deep(ol) {
    padding-left: 1.25rem;
}
.kinetix-help-content :deep(li) {
    margin-bottom: 0.35rem;
    list-style: disc;
}
.kinetix-help-content :deep(ol li) {
    list-style: decimal;
}
.kinetix-help-content :deep(li)::marker {
    color: var(--border);
}
.kinetix-help-content :deep(strong) {
    color: var(--foreground);
    font-weight: 600;
}
.kinetix-help-content :deep(a) {
    color: var(--primary);
    text-decoration: underline;
    text-underline-offset: 2px;
}
.kinetix-help-content :deep(code) {
    font-family: ui-monospace, monospace;
    font-size: 0.85em;
    background: var(--muted);
    padding: 0.1rem 0.35rem;
    border-radius: 0.3rem;
}
.kinetix-help-content :deep(blockquote) {
    border-left: 3px solid var(--primary);
    padding: 0.25rem 0 0.25rem 1rem;
    margin: 0 0 1rem;
    color: var(--muted-foreground);
    font-style: italic;
}
.kinetix-help-content :deep(img) {
    max-width: 100%;
    height: auto;
    border-radius: 0.75rem;
    border: 1px solid var(--border);
    margin: 0.75rem 0 1.5rem;
    box-shadow: 0 1px 3px rgb(0 0 0 / 0.08);
}
.kinetix-help-content :deep(hr) {
    border: 0;
    border-top: 1px solid var(--border);
    margin: 2rem 0;
}
</style>
