import { describe, expect, it, vi, beforeEach } from 'vitest';

const fetchMock = vi.fn();

vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...args: unknown[]) => fetchMock(...args),
    kinetixRoutePrefix: () => '_kinetix',
    isKinetixAbort: (e: unknown) =>
        e instanceof Error && e.name === 'AbortError',
}));
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));

import {
    applyFormChanges,
    useKinetixFormReactivity,
} from '@/composables/useKinetixFormReactivity';

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

    it('names the live fields that changed, in order, so only their hooks run', async () => {
        fetchMock.mockResolvedValue({ schema: [], changes: {} });

        const { onFieldChange } = useKinetixFormReactivity({
            descriptor: () => 'signed-token',
            getValues: () => ({}),
            onSchema: () => {},
            onChanges: () => {},
            debounce: 20,
        });

        onFieldChange(true, 'country');
        onFieldChange(true, 'state');
        onFieldChange(true, 'country');
        await new Promise((r) => setTimeout(r, 40));
        await flush();

        const [, opts] = fetchMock.mock.calls[0] as [string, any];
        expect(opts.body.changed).toEqual(['country', 'state']);
    });

    // A response computed from a value the user has since replaced used to
    // land while the next request was still debouncing.
    it('drops the response of a request a newer change superseded', async () => {
        let resolveFirst!: (value: unknown) => void;
        fetchMock.mockImplementationOnce(
            (_url: string, opts: { signal: AbortSignal }) =>
                new Promise((resolve, reject) => {
                    resolveFirst = resolve;
                    opts.signal.addEventListener('abort', () => {
                        const error = new Error('aborted');
                        error.name = 'AbortError';
                        reject(error);
                    });
                }),
        );
        const onChanges = vi.fn();

        const { onFieldChange } = useKinetixFormReactivity({
            descriptor: () => 'signed-token',
            getValues: () => ({}),
            onSchema: () => {},
            onChanges,
            debounce: 0,
        });

        onFieldChange(true, 'country');
        await flush();
        expect(fetchMock).toHaveBeenCalledTimes(1);

        // The user changes the field again before the response arrives…
        fetchMock.mockReturnValue(new Promise(() => {}));
        onFieldChange(true, 'country');
        resolveFirst({ schema: [], changes: { state: null } });
        await flush();

        // …so the first response's changes never apply.
        expect(onChanges).not.toHaveBeenCalled();
    });

    it('gives focus back only when the schema swap took it away', async () => {
        document.body.innerHTML =
            '<input id="country" value="es" /><input id="note" />';
        const country = document.getElementById('country') as HTMLInputElement;
        const note = document.getElementById('note') as HTMLInputElement;
        const frame = () => new Promise((r) => requestAnimationFrame(r));

        let resolve!: (value: unknown) => void;
        fetchMock.mockImplementation(() => new Promise((r) => (resolve = r)));

        const { onFieldChange } = useKinetixFormReactivity({
            descriptor: () => 'signed-token',
            getValues: () => ({}),
            onSchema: () => {},
            onChanges: () => {},
            debounce: 0,
        });

        // The user moved on to another field while the request ran.
        country.focus();
        onFieldChange(true, 'country');
        await flush();
        note.focus();
        resolve({ schema: [], changes: {} });
        await flush();
        await frame();
        expect(document.activeElement).toBe(note);

        // The swap blurred the input: focus returns to it.
        country.focus();
        onFieldChange(true, 'country');
        await flush();
        country.blur();
        resolve({ schema: [], changes: {} });
        await flush();
        await frame();
        expect(document.activeElement).toBe(country);
    });
});

describe('applyFormChanges', () => {
    it('updates a nested value by path and keeps its siblings', () => {
        const values = {
            title: 'Order',
            items: [
                { name: 'A', qty: 1 },
                { name: 'B', qty: 2 },
            ],
        };

        const next = applyFormChanges(values, { 'items.0.qty': 5, title: 'X' });

        expect(next).toEqual({
            title: 'X',
            items: [
                { name: 'A', qty: 5 },
                { name: 'B', qty: 2 },
            ],
        });
        // The original values are not mutated.
        expect(values.items[0].qty).toBe(1);
    });

    it('creates the containers a path needs', () => {
        expect(applyFormChanges({}, { 'meta.tags.0': 'x' })).toEqual({
            meta: { tags: ['x'] },
        });
    });
});
