import { describe, expect, it } from 'vitest';
import { searchKinetixDocs } from '@/composables/useKinetixDocsSearch';
import type { KinetixDocsSearchEntry } from '@/types/kinetix';

const entry = (
    overrides: Partial<KinetixDocsSearchEntry>,
): KinetixDocsSearchEntry => ({
    section: 'guide',
    sectionTitle: 'Guide',
    slug: 'page',
    title: 'Page',
    group: '',
    url: '/docs/guide/page',
    headings: [],
    text: '',
    ...overrides,
});

const index = [
    entry({
        slug: 'install',
        title: 'Install',
        group: 'Basics',
        url: '/docs/guide/install',
        headings: [{ id: 'requirements', text: 'Requirements' }],
        text: 'Install the CLI. You need PHP 8.3 and a configuración file.',
    }),
    entry({
        slug: 'tokens',
        title: 'Tokens',
        url: '/docs/guide/tokens',
        headings: [{ id: 'rotate', text: 'Rotate a token' }],
        text: 'Tokens authenticate the CLI. Install one per machine.',
    }),
    entry({
        slug: 'webhooks',
        title: 'Webhooks',
        url: '/docs/guide/webhooks',
        text: 'Deliveries retry three times.',
    }),
];

describe('searchKinetixDocs', () => {
    it('ranks a title match above a text match', () => {
        const results = searchKinetixDocs(index, 'install');

        expect(results.map((r) => r.title)).toEqual(['Install', 'Tokens']);
        expect(results[0].context).toBe('Guide · Basics');
    });

    it('needs every word, in any part of the page', () => {
        expect(searchKinetixDocs(index, 'cli php').map((r) => r.title)).toEqual(
            ['Install'],
        );
        expect(searchKinetixDocs(index, 'cli nowhere')).toEqual([]);
        expect(searchKinetixDocs(index, '   ')).toEqual([]);
    });

    it('links to the heading that matches', () => {
        const [result] = searchKinetixDocs(index, 'rotate');

        expect(result.url).toBe('/docs/guide/tokens#rotate');
        expect(result.context).toBe('Rotate a token');
    });

    it('ignores accents and case, and marks the original text', () => {
        const [result] = searchKinetixDocs(index, 'CONFIGURACION');

        expect(result.title).toBe('Install');
        expect(
            result.excerpt.filter((part) => part.match).map((p) => p.text),
        ).toEqual(['configuración']);
    });

    it('limits the results', () => {
        const many = Array.from({ length: 30 }, (_, i) =>
            entry({ slug: `p${i}`, title: `Page ${i}`, text: 'shared word' }),
        );

        expect(searchKinetixDocs(many, 'shared')).toHaveLength(12);
        expect(searchKinetixDocs(many, 'shared', 5)).toHaveLength(5);
    });
});
