<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Languages } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixHelp } from '@/composables/useKinetixHelp';
import { useKinetixHelpToc } from '@/composables/useKinetixHelpToc';
import { useKinetixLocale } from '@/composables/useKinetixLocale';
import KinetixMarkdownContent from './KinetixMarkdownContent.vue';
import KinetixPageToc from './KinetixPageToc.vue';
import Alert from './primitives/Alert.vue';
import AlertDescription from './primitives/AlertDescription.vue';

/**
 * A rendered Help Center article: server-sanitized HTML (permission-gated
 * blocks already stripped), an "on this page" TOC with scroll tracking, and
 * prev/next navigation. Internal links inside the article body are routed
 * through Inertia so cross-article markdown links stay client-side.
 *
 * The body follows the app's language and re-fetches when the user switches
 * it. When an article isn't translated yet the fallback is explicit, never
 * silent: the content is marked with its real `lang` (so screen readers and
 * `dir="auto"` behave), a notice says which language is being shown, and — for
 * articles that exist in more than one language — chips let the reader open
 * another one without changing the whole app's locale.
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
        /** Hide the per-article language chips (keep the fallback notice). */
        hideLanguageSwitcher?: boolean;
        /** Open images in the lightbox; unset follows `kinetix.lightbox.markdown`. */
        lightbox?: boolean;
    }>(),
    {
        articleHref: undefined,
        indexHref: undefined,
        hideLanguageSwitcher: false,
        lightbox: undefined,
    },
);

const { t } = useI18n();
const { article, articleLoading, articleError, loadArticle } = useKinetixHelp();
const { locales } = useKinetixLocale();

const markdown = ref<InstanceType<typeof KinetixMarkdownContent> | null>(null);
const contentEl = computed(() => markdown.value?.el ?? null);
const { toc, activeId, build, scrollTo } = useKinetixHelpToc(contentEl);

/** Explicitly chosen reading language (null = follow the app's). */
const viewLocale = ref<string | null>(null);

/**
 * Languages this article can be read in — empty (so no switcher renders) when
 * the switcher is off or the payload predates it.
 */
const languageChoices = computed<string[]>(() =>
    props.hideLanguageSwitcher ? [] : (article.value?.availableLocales ?? []),
);

/** Native label for a locale code, falling back to the code itself. */
const localeLabel = (code: string): string =>
    locales.value.find((entry) => entry.code === code)?.label ??
    code.toUpperCase();

const readIn = (code: string): void => {
    viewLocale.value = code;
    void loadArticle(props.slug, code);
};

const indexPath = (): string =>
    props.indexHref ??
    window.location.pathname.replace(/\/+$/, '').replace(/\/[^/]+$/, '');

const href = (slug: string): string =>
    props.articleHref ? props.articleHref(slug) : `${indexPath()}/${slug}`;

async function load(): Promise<void> {
    viewLocale.value = null;
    await loadArticle(props.slug);
}

onMounted(load);
watch(() => props.slug, load);

// Rebuild the TOC whenever the body changes — a new article, a language switch
// from the app's selector, or a per-article language chip. Headings differ per
// language, so the anchors must be regenerated, not reused.
watch(
    () => article.value?.html,
    async () => {
        await nextTick();
        build();
    },
);

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
            <KinetixPageToc
                v-if="toc.length"
                class="mb-6 xl:hidden"
                variant="disclosure"
                :entries="toc"
                @navigate="scrollTo"
            />

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
                <!-- Language: which one you're reading, and the others available -->
                <div
                    v-if="article.isFallback || languageChoices.length > 1"
                    class="mb-6 space-y-3 max-w-3xl mx-auto"
                >
                    <Alert v-if="article.isFallback">
                        <AlertDescription>
                            {{
                                t('kinetix.help_translation_missing', {
                                    language: localeLabel(article.locale),
                                })
                            }}
                        </AlertDescription>
                    </Alert>

                    <div
                        v-if="languageChoices.length > 1"
                        class="gap-2 flex flex-wrap items-center"
                    >
                        <span
                            class="gap-1.5 text-xs font-medium flex items-center text-muted-foreground"
                        >
                            <Languages class="size-3.5" aria-hidden="true" />
                            {{ t('kinetix.help_read_in') }}
                        </span>
                        <button
                            v-for="code in languageChoices"
                            :key="code"
                            type="button"
                            :lang="code"
                            :aria-current="
                                code === article.locale ? 'true' : undefined
                            "
                            :class="[
                                'px-2 py-0.5 text-xs font-medium rounded-full border transition-colors outline-none focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50',
                                code === article.locale
                                    ? 'border-primary bg-primary/10 text-primary'
                                    : 'border-border text-muted-foreground hover:bg-accent hover:text-foreground',
                            ]"
                            @click="readIn(code)"
                        >
                            {{ localeLabel(code) }}
                        </button>
                    </div>
                </div>

                <KinetixMarkdownContent
                    ref="markdown"
                    as="article"
                    class="max-w-3xl mx-auto"
                    :html="article.html"
                    :lightbox="lightbox"
                    :lang="article.locale"
                    dir="auto"
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
            <KinetixPageToc
                :entries="toc"
                :active-id="activeId"
                @navigate="scrollTo"
            />
        </aside>
    </div>
</template>
