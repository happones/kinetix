import { onBeforeUnmount, ref } from 'vue';
import type { Ref } from 'vue';

export interface KinetixHelpTocEntry {
    id: string;
    text: string;
    level: number;
}

/**
 * "On this page" table of contents for a server-rendered help article. The
 * markdown HTML ships without heading ids, so `build()` assigns a slugified,
 * deduplicated id to every h2/h3 inside the content element, and an
 * IntersectionObserver tracks the section currently at the top of the
 * viewport. Call `build()` after the article HTML is in the DOM (and again on
 * navigation); the observer disconnects automatically on unmount.
 */
export function useKinetixHelpToc(contentEl: Ref<HTMLElement | null>) {
    const toc = ref<KinetixHelpTocEntry[]>([]);
    const activeId = ref('');
    let observer: IntersectionObserver | null = null;

    const slugify = (text: string): string =>
        text
            .toLowerCase()
            .trim()
            .replace(/[^\w\s-]/g, '')
            .replace(/\s+/g, '-')
            .replace(/-+/g, '-');

    function build(): void {
        observer?.disconnect();

        const root = contentEl.value;

        if (!root) {
            toc.value = [];

            return;
        }

        const seen = new Set<string>();
        const headings = Array.from(
            root.querySelectorAll<HTMLElement>('h2, h3'),
        );

        toc.value = headings.map((el) => {
            const text = el.textContent?.trim() ?? '';
            let id = el.id || slugify(text) || 'section';

            while (seen.has(id)) {
                id = `${id}-1`;
            }

            seen.add(id);
            el.id = id;

            return { id, text, level: el.tagName === 'H3' ? 3 : 2 };
        });

        activeId.value = toc.value[0]?.id ?? '';

        if (typeof IntersectionObserver === 'undefined') {
            return;
        }

        // Highlight the heading currently entering the top of the viewport.
        observer = new IntersectionObserver(
            (entries) => {
                for (const entry of entries) {
                    if (entry.isIntersecting) {
                        activeId.value = entry.target.id;
                    }
                }
            },
            { rootMargin: '-80px 0px -66% 0px', threshold: 0 },
        );

        headings.forEach((el) => observer?.observe(el));
    }

    function scrollTo(id: string): void {
        activeId.value = id;
        contentEl.value
            ?.querySelector(`#${CSS.escape(id)}`)
            ?.scrollIntoView({ behavior: 'smooth', block: 'start' });
    }

    onBeforeUnmount(() => observer?.disconnect());

    return { toc, activeId, build, scrollTo };
}
