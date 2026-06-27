import { beforeEach, describe, expect, it, vi } from 'vitest';

const fetchMock = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: {} }),
}));

vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
}));

import { useKinetixTokens } from '@/composables/useKinetixTokens';

describe('useKinetixTokens', () => {
    beforeEach(() => {
        fetchMock.mockReset();
    });

    it('loads tokens and the scope catalog', async () => {
        fetchMock.mockResolvedValue({
            tokens: [{ id: 1, name: 'CI', abilities: ['posts.read'] }],
            scopes: { 'posts.read': 'Read posts' },
        });

        const tokens = useKinetixTokens();
        await tokens.load();

        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/tokens');
        expect(tokens.tokens.value).toHaveLength(1);
        expect(tokens.scopes.value).toEqual({ 'posts.read': 'Read posts' });
    });

    it('posts the name and chosen abilities on create', async () => {
        fetchMock.mockResolvedValue({ token: {}, plainTextToken: 'tok_abc' });

        const result = await useKinetixTokens().create({
            name: 'Deploy',
            abilities: ['posts.read'],
        });

        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/tokens', {
            method: 'POST',
            body: { name: 'Deploy', abilities: ['posts.read'] },
        });
        expect(result?.plainTextToken).toBe('tok_abc');
    });

    it('deletes a token by id', async () => {
        fetchMock.mockResolvedValue({ status: 'success' });

        await useKinetixTokens().remove({
            id: 7,
            name: 'old',
            abilities: [],
            lastUsedAt: null,
            createdAt: null,
        });

        expect(fetchMock).toHaveBeenCalledWith('/_kinetix/tokens/7', {
            method: 'DELETE',
        });
    });
});
