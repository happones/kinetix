<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { ChevronLeft, ChevronRight, Menu, Pencil } from '@lucide/vue';
import { computed, nextTick, onMounted, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { useKinetixHelpToc } from '@/composables/useKinetixHelpToc';
import { useKinetixLocale } from '@/composables/useKinetixLocale';
import type { KinetixDocsPayload } from '@/types/kinetix';
import KinetixDocsNav from './KinetixDocsNav.vue';
import KinetixDocsSearch from './KinetixDocsSearch.vue';
import KinetixMarkdownContent from './KinetixMarkdownContent.vue';
import KinetixPageToc from './KinetixPageToc.vue';
import Alert from './primitives/Alert.vue';
import AlertDescription from './primitives/AlertDescription.vue';

/**
 * A docs page, as DocsController renders it: the sections, the section's
 * pages by group, the page itself (with an "on this page" rail and previous /
 * next links) and a search box. Mount it in your docs page, inside whatever
 * layout the docs live in:
 *
 *   <KinetixDocs :docs="docs" />
 *
 * Links between docs pages (in the navigation and in the page body) are
 * Inertia visits. A page served in the fallback language says so, and its
 * body carries its real `lang`.
 */
const props = defineProps<{
    docs: KinetixDocsPayload;
}>();

const { t, locale } = useI18n();
const { locales } = useKinetixLocale();

const markdown = ref<InstanceType<typeof KinetixMarkdownContent> | null>(null);
const contentEl = computed(() => markdown.value?.el ?? null);
const { toc, activeId, build, scrollTo } = useKinetixHelpToc(contentEl);

const languageName = computed(
    () =>
        locales.value.find((entry) => entry.code === props.docs.page.locale)
            ?.label ?? props.docs.page.locale.toUpperCase(),
);

const updated = computed(() => {
    const date = props.docs.page.updatedAt
        ? new Date(props.docs.page.updatedAt)
        : null;

    return date && !Number.isNaN(date.getTime())
        ? new Intl.DateTimeFormat(locale.value, { dateStyle: 'medium' }).format(
              date,
          )
        : null;
});

/** Jump to a heading and keep it in the address bar, so it can be shared. */
function navigate(id: string): void {
    scrollTo(id);
    window.history.replaceState(window.history.state, '', `#${id}`);
}

/**
 * Links to this app's pages go through Inertia; `#heading` links scroll;
 * anything else (other sites, new tabs, modified clicks) keeps its default.
 */
function onContentClick(event: MouseEvent): void {
    const anchor = (event.target as HTMLElement).closest('a');

    if (
        !anchor ||
        anchor.target ||
        event.defaultPrevented ||
        event.button !== 0 ||
        event.metaKey ||
        event.ctrlKey ||
        event.shiftKey ||
        event.altKey
    ) {
        return;
    }

    const href = anchor.getAttribute('href') ?? '';

    if (href.startsWith('#')) {
        event.preventDefault();
        navigate(decodeURIComponent(href.slice(1)));
    } else if (href.startsWith('/') && !href.startsWith('//')) {
        event.preventDefault();
        router.visit(href);
    }
}

async function refresh(): Promise<void> {
    await nextTick();
    build();

    const hash = decodeURIComponent(window.location.hash.slice(1));

    if (hash) {
        contentEl.value
            ?.querySelector(`#${CSS.escape(hash)}`)
            ?.scrollIntoView({ block: 'start' });
    }
}

onMounted(refresh);
watch(() => props.docs.page.html, refresh);
</script>

<template>
    <div class="mx-auto w-full max-w-[96rem]">
        <Head :title="docs.meta.title">
            <meta
                v-if="docs.meta.description"
                head-key="description"
                name="description"
                :content="docs.meta.description"
            />
        </Head>

        <!-- Sections + search -->
        <div
            class="mb-6 gap-3 pb-3 sm:flex-row sm:items-center sm:justify-between flex flex-col border-b border-border"
        >
            <nav
                v-if="docs.sections.length > 1"
                :aria-label="t('kinetix.docs_sections')"
                class="gap-1 -mx-1 px-1 min-w-0 flex overflow-x-auto"
            >
                <Link
                    v-for="section in docs.sections"
                    :key="section.key"
                    :href="section.url"
                    :aria-current="section.active ? 'page' : undefined"
                    :class="[
                        'px-3 py-1.5 text-sm rounded-md whitespace-nowrap transition-colors',
                        section.active
                            ? 'font-medium bg-accent text-foreground'
                            : 'text-muted-foreground hover:bg-accent/60 hover:text-foreground',
                    ]"
                >
                    {{ section.title }}
                </Link>
            </nav>
            <p v-else class="text-sm font-medium text-foreground">
                {{ docs.section.title }}
            </p>
            <KinetixDocsSearch :url="docs.searchUrl" />
        </div>

        <div class="gap-8 flex">
            <!-- Left: the section's pages (lg+) -->
            <aside class="w-60 lg:block hidden shrink-0">
                <div
                    class="top-4 sticky max-h-[calc(100vh-2rem)] overflow-y-auto"
                >
                    <KinetixDocsNav
                        :groups="docs.navigation"
                        :label="t('kinetix.docs_pages')"
                    />
                </div>
            </aside>

            <div class="min-w-0 flex-1">
                <div class="mb-6 space-y-3 lg:hidden">
                    <details class="rounded-lg border border-border bg-card">
                        <summary
                            class="gap-2 px-4 py-2.5 text-sm font-medium flex cursor-pointer items-center select-none"
                        >
                            <Menu class="size-4 text-muted-foreground" />
                            {{ t('kinetix.docs_menu') }}
                        </summary>
                        <div class="p-2 border-t border-border">
                            <KinetixDocsNav
                                :groups="docs.navigation"
                                :label="t('kinetix.docs_pages')"
                            />
                        </div>
                    </details>
                </div>

                <KinetixPageToc
                    v-if="toc.length"
                    class="mb-6 xl:hidden"
                    variant="disclosure"
                    :entries="toc"
                    @navigate="navigate"
                />

                <div class="max-w-3xl mx-auto">
                    <header class="mb-8">
                        <p
                            v-if="docs.page.group"
                            class="mb-2 text-xs font-semibold tracking-wide text-primary uppercase"
                        >
                            {{ docs.page.group }}
                        </p>
                        <h1
                            class="text-3xl font-bold tracking-tight [overflow-wrap:anywhere] text-foreground"
                        >
                            {{ docs.page.title }}
                        </h1>
                        <p
                            v-if="docs.page.description"
                            class="mt-2 text-lg text-muted-foreground"
                        >
                            {{ docs.page.description }}
                        </p>
                    </header>

                    <Alert v-if="docs.page.fallback" class="mb-6" role="note">
                        <AlertDescription>
                            {{
                                t('kinetix.docs_translation_missing', {
                                    language: languageName,
                                })
                            }}
                        </AlertDescription>
                    </Alert>

                    <KinetixMarkdownContent
                        ref="markdown"
                        as="article"
                        :html="docs.page.html"
                        :lang="docs.page.locale"
                        dir="auto"
                        @click="onContentClick"
                    />

                    <div
                        v-if="docs.page.editUrl || updated"
                        class="mt-10 gap-3 text-sm flex flex-wrap items-center justify-between text-muted-foreground"
                    >
                        <a
                            v-if="docs.page.editUrl"
                            :href="docs.page.editUrl"
                            target="_blank"
                            rel="noopener noreferrer"
                            class="gap-1.5 inline-flex items-center hover:text-foreground"
                        >
                            <Pencil class="size-3.5" aria-hidden="true" />
                            {{ t('kinetix.docs_edit_page') }}
                        </a>
                        <span v-if="updated">
                            {{ t('kinetix.docs_updated', { date: updated }) }}
                        </span>
                    </div>

                    <nav
                        v-if="docs.previous || docs.next"
                        class="mt-10 gap-3 pt-6 sm:grid-cols-2 grid grid-cols-1 border-t border-border"
                    >
                        <Link
                            v-if="docs.previous"
                            :href="docs.previous.url"
                            class="p-4 rounded-lg border border-border transition-colors hover:border-primary/50"
                        >
                            <span
                                class="gap-1 text-xs flex items-center text-muted-foreground"
                            >
                                <ChevronLeft
                                    class="size-3"
                                    aria-hidden="true"
                                />
                                {{ t('kinetix.docs_previous') }}
                            </span>
                            <span
                                class="mt-1 font-medium block text-foreground"
                            >
                                {{ docs.previous.title }}
                            </span>
                        </Link>
                        <span v-else aria-hidden="true" />
                        <Link
                            v-if="docs.next"
                            :href="docs.next.url"
                            class="p-4 rounded-lg border border-border text-right transition-colors hover:border-primary/50"
                        >
                            <span
                                class="gap-1 text-xs flex items-center justify-end text-muted-foreground"
                            >
                                {{ t('kinetix.docs_next') }}
                                <ChevronRight
                                    class="size-3"
                                    aria-hidden="true"
                                />
                            </span>
                            <span
                                class="mt-1 font-medium block text-foreground"
                            >
                                {{ docs.next.title }}
                            </span>
                        </Link>
                    </nav>
                </div>
            </div>

            <!-- Right: on this page (xl+) -->
            <aside v-if="toc.length" class="w-56 xl:block hidden shrink-0">
                <KinetixPageToc
                    :entries="toc"
                    :active-id="activeId"
                    @navigate="navigate"
                />
            </aside>
        </div>
    </div>
</template>
