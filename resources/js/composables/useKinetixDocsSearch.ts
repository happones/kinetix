import { ref } from 'vue';
import type { KinetixDocsSearchEntry } from '@/types/kinetix';

export interface KinetixDocsSearchResult {
    key: string;
    title: string;
    /** Section · group, or the matching heading. */
    context: string;
    /** The page, or the matching heading on it. */
    url: string;
    /** The text around the first match, split so the matches can be marked. */
    excerpt: Array<{ text: string; match: boolean }>;
}

/**
 * Folds one character for matching: lower case, accents dropped
 * (`É` → `e`). One character in, one character out, so a position found in
 * the folded text is the same position in the original.
 */
const foldChar = (char: string): string =>
    (char.normalize('NFD')[0] ?? char).toLowerCase();

const fold = (text: string): string[] => Array.from(text, foldChar);

const indexOf = (haystack: string[], needle: string[]): number => {
    outer: for (let i = 0; i <= haystack.length - needle.length; i++) {
        for (let j = 0; j < needle.length; j++) {
            if (haystack[i + j] !== needle[j]) {
                continue outer;
            }
        }

        return i;
    }

    return -1;
};

const contains = (text: string, term: string[]): boolean =>
    indexOf(fold(text), term) !== -1;

/** The text around the first match of any term, matches marked. */
function excerptFor(
    text: string,
    terms: string[][],
    radius = 70,
): Array<{ text: string; match: boolean }> {
    const chars = Array.from(text);
    const folded = chars.map(foldChar);
    const first = Math.min(
        ...terms
            .map((term) => indexOf(folded, term))
            .filter((index) => index !== -1),
    );

    if (!Number.isFinite(first)) {
        return [{ text: chars.slice(0, radius * 2).join(''), match: false }];
    }

    const start = Math.max(0, first - radius);
    const end = Math.min(chars.length, first + radius);
    const marked = new Array<boolean>(end - start).fill(false);

    for (const term of terms) {
        for (let i = start; i <= end - term.length; i++) {
            if (term.every((char, j) => folded[i + j] === char)) {
                marked.fill(true, i - start, i - start + term.length);
            }
        }
    }

    const parts: Array<{ text: string; match: boolean }> = [];

    for (let i = start; i < end; i++) {
        const match = marked[i - start];
        const last = parts[parts.length - 1];

        if (last && last.match === match) {
            last.text += chars[i];
        } else {
            parts.push({ text: chars[i], match });
        }
    }

    if (start > 0) {
        parts.unshift({ text: '…', match: false });
    }

    if (end < chars.length) {
        parts.push({ text: '…', match: false });
    }

    return parts;
}

/**
 * Ranks docs pages for a query. Every word must appear in the page (its
 * title, a heading or its text, accents ignored); a word in the title counts
 * most, then one in a heading — and a heading that matches is where the
 * result links.
 */
export function searchKinetixDocs(
    entries: KinetixDocsSearchEntry[],
    query: string,
    limit = 12,
): KinetixDocsSearchResult[] {
    const terms = query
        .trim()
        .split(/\s+/)
        .filter(Boolean)
        .map((term) => fold(term));

    if (terms.length === 0) {
        return [];
    }

    const scored: Array<{ score: number; result: KinetixDocsSearchResult }> =
        [];

    for (const entry of entries) {
        const everything = [
            entry.title,
            ...entry.headings.map((heading) => heading.text),
            entry.text,
        ].join(' ');

        if (!terms.every((term) => contains(everything, term))) {
            continue;
        }

        const heading = entry.headings.find((candidate) =>
            terms.some((term) => contains(candidate.text, term)),
        );
        const score = terms.reduce(
            (total, term) =>
                total +
                (contains(entry.title, term)
                    ? 10
                    : entry.headings.some((h) => contains(h.text, term))
                      ? 5
                      : 1),
            0,
        );

        scored.push({
            score,
            result: {
                key: `${entry.section}/${entry.slug}${heading ? `#${heading.id}` : ''}`,
                title: entry.title,
                context: heading
                    ? heading.text
                    : [entry.sectionTitle, entry.group]
                          .filter(Boolean)
                          .join(' · '),
                url: heading ? `${entry.url}#${heading.id}` : entry.url,
                excerpt: excerptFor(entry.text, terms),
            },
        });
    }

    return scored
        .sort(
            (a, b) =>
                b.score - a.score ||
                a.result.title.localeCompare(b.result.title),
        )
        .slice(0, limit)
        .map((entry) => entry.result);
}

/**
 * The search index, fetched once per URL on first use and kept for the
 * session (the docs search box opens it again and again).
 */
const indexes = new Map<string, Promise<KinetixDocsSearchEntry[]>>();

export function useKinetixDocsSearch() {
    const entries = ref<KinetixDocsSearchEntry[]>([]);
    const loading = ref(false);
    const failed = ref(false);

    async function load(url: string): Promise<void> {
        if (!indexes.has(url)) {
            indexes.set(
                url,
                fetch(url, {
                    headers: { Accept: 'application/json' },
                    credentials: 'same-origin',
                })
                    .then((response) => {
                        if (!response.ok) {
                            throw new Error(String(response.status));
                        }

                        return response.json();
                    })
                    .then(
                        (body: { data?: KinetixDocsSearchEntry[] }) =>
                            body.data ?? [],
                    ),
            );
        }

        loading.value = true;
        failed.value = false;

        try {
            entries.value = await indexes.get(url)!;
        } catch {
            indexes.delete(url);
            failed.value = true;
        } finally {
            loading.value = false;
        }
    }

    return { entries, loading, failed, load };
}

/** For tests: forget the fetched indexes. */
export function clearKinetixDocsSearchCache(): void {
    indexes.clear();
}
