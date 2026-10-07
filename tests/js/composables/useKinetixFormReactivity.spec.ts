import { describe, expect, it, vi, beforeEach } from 'vitest';

const fetchMock = vi.fn();

vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
    isKinetixAbort: (e: unknown) =>
        e instanceof Error && e.name === 'AbortError',
}));
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));

import { useKinetixFormReactivity } from '@/composables/useKinetixFormReactivity';

const flush = () => new Promise((r) => setTimeout(r, 0));

describe('useKinetixFormReactivity', () => {
    beforeEach(() => {
        fetchMock.mockReset();
    });

    it('recomputes on a live field change and applies schema + changes', async () => {
        fetchMock.mockResolvedValue({
            schema: [{ name: 'state', options: { par: 'Paris' } }],
            changes: { state: null },
        });

        let schema: unknown[] = [];
        const values: Record<string, unknown> = { country: 'fr', state: 'mad' };

        const { onFieldChange } = useKinetixFormReactivity({
            descriptor: () => 'signed-token',
            getValues: () => values,
            onSchema: (s) => {
                schema = s;
            },
            onChanges: (c) => Object.assign(values, c),
            debounce: 0,
        });

        onFieldChange(true);
        await flush();
        await flush();

        expect(fetchMock).toHaveBeenCalledTimes(1);
        const [url, opts] = fetchMock.mock.calls[0] as [string, any];
        expect(url).toBe('/_kinetix/forms/recompute');
        expect(opts.body.descriptor).toBe('signed-token');
        expect(opts.body.data.country).toBe('fr');
        expect(schema).toEqual([{ name: 'state', options: { par: 'Paris' } }]);
        expect(values.state).toBeNull();
    });

    it('does not recompute for a non-live field', async () => {
        const { onFieldChange } = useKinetixFormReactivity({
            descriptor: () => 'signed-token',
            getValues: () => ({}),
            onSchema: () => {},
            onChanges: () => {},
            debounce: 0,
        });

        onFieldChange(false);
        await flush();

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('does nothing when the form has no descriptor (not reactive)', async () => {
        const { onFieldChange } = useKinetixFormReactivity({
            descriptor: () => null,
            getValues: () => ({}),
            onSchema: () => {},
            onChanges: () => {},
            debounce: 0,
        });

        onFieldChange(true);
        await flush();

        expect(fetchMock).not.toHaveBeenCalled();
    });

    it('debounces rapid changes into a single request', async () => {
        fetchMock.mockResolvedValue({ schema: [], changes: {} });

        const { onFieldChange } = useKinetixFormReactivity({
            descriptor: () => 'signed-token',
            getValues: () => ({}),
            onSchema: () => {},
            onChanges: () => {},
            debounce: 20,
        });

        onFieldChange(true);
        onFieldChange(true);
        onFieldChange(true);
        await new Promise((r) => setTimeout(r, 40));
        await flush();

        expect(fetchMock).toHaveBeenCalledTimes(1);
    });
});
