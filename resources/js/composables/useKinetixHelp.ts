import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type {
    KinetixHelpArticleDetail,
    KinetixHelpArticleSummary,
    KinetixHelpSearchResult,
    KinetixSharedProps,
} from '@/types';

/**
 * Data layer for the Help Center components, talking to Kinetix's help
 * endpoints (article list, rendered article, search). The route prefix
 * (incl. any team segment) comes from the shared `kinetix_config`, and every
 * payload is already permission-filtered server-side.
 */
export function useKinetixHelp() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/help`;

    const articles = ref<KinetixHelpArticleSummary[]>([]);
    const loading = ref(false);

    const article = ref<KinetixHelpArticleDetail | null>(null);
    const articleLoading = ref(false);
    const articleError = ref(false);

    const results = ref<KinetixHelpSearchResult[]>([]);
    const searching = ref(false);

    async function loadArticles(): Promise<void> {
        loading.value = true;

        try {
            const data = await kinetixFetch<{
                articles: KinetixHelpArticleSummary[];
            }>(base());

            articles.value = data?.articles ?? [];
        } finally {
            loading.value = false;
        }
    }

    async function loadArticle(slug: string): Promise<void> {
        articleLoading.value = true;
        articleError.value = false;

        try {
            article.value = await kinetixFetch<KinetixHelpArticleDetail>(
                `${base()}/article/${encodeURIComponent(slug)}`,
            );
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
            }>(`${base()}/search?q=${encodeURIComponent(term)}`);

            results.value = data?.results ?? [];
        } finally {
            searching.value = false;
        }
    }

    return {
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
