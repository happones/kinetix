import { beforeEach, describe, expect, it, vi } from 'vitest';

type Handler = (event: { detail: { flash: unknown } }) => void;

const page = { flash: {} as Record<string, unknown> };
const handlers = new Set<Handler>();
const flashCalls: unknown[] = [];

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
    router: {
        on: (_: string, handler: Handler) => {
            handlers.add(handler);

            return () => handlers.delete(handler);
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
        page.flash = {};
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
