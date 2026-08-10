import { usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';
import type { Ref } from 'vue';
import { useI18n } from 'vue-i18n';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type {
    KinetixHelpArticleDetail,
    KinetixHelpArticleSummary,
    KinetixHelpSearchResult,
    KinetixSharedProps,
} from '@/types/kinetix';

/**
 * Client cache, keyed by locale (and slug), shared across every Help Center
 * instance for the page's lifetime. Switching language back and forth — or
 * walking prev/next through articles already read — costs no request, and a
 * cache entry can never be served to the wrong language because the language
 * IS the key.
 */
const articleListCache = new Map<string, KinetixHelpArticleSummary[]>();
const articleCache = new Map<string, KinetixHelpArticleDetail>();

/** Drop every cached help payload (call after editing articles in-app). */
export function clearKinetixHelpCache(): void {
    articleListCache.clear();
    articleCache.clear();
}

/**
 * Data layer for the Help Center components, talking to Kinetix's help
 * endpoints (article list, rendered article, search). The route prefix
 * (incl. any team segment) comes from the shared `kinetix_config`, and every
 * payload is already permission-filtered server-side.
 *
 * **Locale-aware end to end.** Every request carries the active language, so
 * the server can serve it without depending on the ambient app locale, the URL
 * varies per language (no cache can hand one language's payload to another),
 * and a switch in the language selector re-fetches the list and the open
 * article automatically — no page reload, no stale copy. `loadArticle()` also
 * accepts an explicit locale, which is what powers the per-article "read this
 * in…" switcher.
 */
export function useKinetixHelp() {
    const page = usePage<KinetixSharedProps>();
    const { locale: i18nLocale } = useI18n();

    /** The language help content is requested in (the app's active one). */
    const locale = computed<string>(
        () =>
            (i18nLocale.value as string) ||
            page.props.kinetix_locale?.current ||
            'en',
    );

    const base = (): string => `/${kinetixRoutePrefix(page)}/help`;

    const withLocale = (url: string, code: string): string =>
        `${url}${url.includes('?') ? '&' : '?'}locale=${encodeURIComponent(code)}`;

    const articles = ref<KinetixHelpArticleSummary[]>([]);
    const loading = ref(false);

    const article = ref<KinetixHelpArticleDetail | null>(null);
    const articleLoading = ref(false);
    const articleError = ref(false);

    const results = ref<KinetixHelpSearchResult[]>([]);
    const searching = ref(false);

    /** What has been asked for, so a locale switch can replay it. */
    const loadedSlug: Ref<string | null> = ref(null);
    const loadedArticleLocale: Ref<string | null> = ref(null);
    const listLoaded = ref(false);

    async function loadArticles(): Promise<void> {
        const code = locale.value;
        listLoaded.value = true;

        const cached = articleListCache.get(code);

        if (cached) {
            articles.value = cached;

            return;
        }

        loading.value = true;

        try {
            const data = await kinetixFetch<{
                articles: KinetixHelpArticleSummary[];
            }>(withLocale(base(), code));

            articles.value = data?.articles ?? [];
            articleListCache.set(code, articles.value);
        } finally {
            loading.value = false;
        }
    }

    /**
     * Load a rendered article. Pass `articleLocale` to read it in a specific
     * language (the per-article switcher) instead of the app's.
     */
    async function loadArticle(
        slug: string,
        articleLocale?: string,
    ): Promise<void> {
        const code = articleLocale ?? locale.value;
        loadedSlug.value = slug;
        loadedArticleLocale.value = articleLocale ?? null;

        const key = `${code}|${slug}`;
        const cached = articleCache.get(key);

        if (cached) {
            article.value = cached;
            articleError.value = false;

            return;
        }

        articleLoading.value = true;
        articleError.value = false;

        try {
            const data = await kinetixFetch<KinetixHelpArticleDetail>(
                withLocale(
                    `${base()}/article/${encodeURIComponent(slug)}`,
                    code,
                ),
            );

            article.value = data;

            if (data) {
                articleCache.set(key, data);
            }
        } catch {
            article.value = null;
            articleError.value = true;
        } finally {
            articleLoading.value = false;
        }
    }

    async function search(query: string): Promise<void> {
        const term = query.trim();

        if (term.length < 2) {
            results.value = [];

            return;
        }

        searching.value = true;

        try {
            const data = await kinetixFetch<{
                results: KinetixHelpSearchResult[];
            }>(
                withLocale(
                    `${base()}/search?q=${encodeURIComponent(term)}`,
                    locale.value,
                ),
            );

            results.value = data?.results ?? [];
        } finally {
            searching.value = false;
        }
    }

    // The app language changed (language switcher): re-fetch whatever this
    // instance is showing. Without this the Help Center would keep rendering
    // the previous language until a full page load, because an Inertia reload
    // re-renders the page component without re-running onMounted.
    watch(locale, () => {
        results.value = [];

        if (listLoaded.value) {
            void loadArticles();
        }

        // An explicitly chosen article language wins over the app's.
        if (loadedSlug.value !== null && loadedArticleLocale.value === null) {
            void loadArticle(loadedSlug.value);
        }
    });

    return {
        locale,
        articles,
        loading,
        article,
        articleLoading,
        articleError,
        results,
        searching,
        loadArticles,
        loadArticle,
        search,
    };
}
