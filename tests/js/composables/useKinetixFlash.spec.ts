import { beforeEach, describe, expect, it, vi } from 'vitest';

type Handler = (event: { detail: Record<string, unknown> }) => void;

const page = {
    flash: {} as Record<string, unknown>,
    props: {} as Record<string, unknown>,
};
const handlers = new Set<Handler>();
const navigateHandlers = new Set<Handler>();
const flashCalls: unknown[] = [];

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    router: {
        on: (name: string, handler: Handler) => {
            const set = name === 'navigate' ? navigateHandlers : handlers;
            set.add(handler);

            return () => set.delete(handler);
        },
        flash: (updater: (current: Record<string, unknown>) => unknown) => {
            const next = updater(page.flash);
            flashCalls.push(next);
            page.flash = next as Record<string, unknown>;
        },
    },
}));

import {
    kinetixFlashOf,
    onKinetixFlash,
    useKinetixFlash,
} from '@/composables/useKinetixFlash';

describe('kinetixFlashOf', () => {
    it('reads the kinetix slice and never returns null', () => {
        expect(kinetixFlashOf({ kinetix: { toasts: [] } })).toEqual({
            toasts: [],
        });
        expect(kinetixFlashOf({ other: 1 })).toEqual({});
        expect(kinetixFlashOf(null)).toEqual({});
    });
});

describe('onKinetixFlash', () => {
    beforeEach(() => {
        handlers.clear();
        navigateHandlers.clear();
        page.flash = {};
        page.props = {};
    });

    it('hands over the flash the page arrived with, then every new one', () => {
        page.flash = { kinetix: { toasts: [{ id: 'a' }] } };
        const seen: unknown[] = [];

        const stop = onKinetixFlash((payload) => seen.push(payload));
        handlers.forEach((h) =>
            h({ detail: { flash: { kinetix: { alerts: [{ id: 'b' }] } } } }),
        );

        expect(seen).toEqual([
            { toasts: [{ id: 'a' }] },
            { alerts: [{ id: 'b' }] },
        ]);

        stop();
        expect(handlers.size).toBe(0);
        expect(navigateHandlers.size).toBe(0);
    });

    it('delivers the kinetix_flash prop of an older server once per id', () => {
        // inertia-laravel < 2.0.16 has no flash channel: the payload is a prop.
        page.props = {
            kinetix_flash: { toasts: [{ id: 'legacy-1', message: 'Saved' }] },
        };
        const seen: unknown[] = [];

        const stop = onKinetixFlash((payload) => seen.push(payload));

        // A later visit carries a new one…
        navigateHandlers.forEach((h) =>
            h({
                detail: {
                    page: {
                        props: {
                            kinetix_flash: {
                                alerts: [{ id: 'legacy-2', title: 'Heads up' }],
                            },
                        },
                    },
                },
            }),
        );
        // …and Back restores the first page, props and all.
        navigateHandlers.forEach((h) =>
            h({ detail: { page: { props: page.props } } }),
        );

        expect(seen).toEqual([
            {},
            { toasts: [{ id: 'legacy-1', message: 'Saved' }], alerts: [] },
            { toasts: [], alerts: [{ id: 'legacy-2', title: 'Heads up' }] },
        ]);
        stop();
    });
});

describe('useKinetixFlash', () => {
    beforeEach(() => {
        flashCalls.length = 0;
        page.flash = { other: 'kept' };
    });

    it('appends toasts and alerts without dropping what is already flashed', () => {
        const flash = useKinetixFlash();

        flash.success('Copied', { description: 'To the clipboard' });
        flash.alert('You are offline', { color: 'warning' });

        expect(page.flash.other).toBe('kept');

        const payload = kinetixFlashOf(page.flash);
        expect(payload.toasts).toHaveLength(1);
        expect(payload.toasts?.[0]).toMatchObject({
            type: 'success',
            message: 'Copied',
            description: 'To the clipboard',
        });
        expect(payload.alerts?.[0]).toMatchObject({
            title: 'You are offline',
            color: 'warning',
            dismissible: true,
            persistent: false,
        });
        expect(payload.toasts?.[0].id).toBeTruthy();
    });
});
