import { describe, expect, it } from 'vitest';
import {
    statusBadgeClass,
    statusButtonClass,
    statusFillClass,
    statusInteractiveTextClass,
    statusSoftClass,
    statusTextClass,
} from '@/composables/useKinetixStatusColor';

describe('useStatusColor', () => {
    it('maps statuses to semantic tokens (not raw palettes)', () => {
        expect(statusBadgeClass('success')).toContain('text-success');
        expect(statusBadgeClass('success')).toContain('bg-success/10');
        expect(statusBadgeClass('warning')).toContain('text-warning');
        expect(statusBadgeClass('info')).toContain('text-info');
        // danger resolves to the built-in destructive token.
        expect(statusBadgeClass('danger')).toContain('text-destructive');
    });

    it('never emits Tailwind status palettes', () => {
        const all = [
            statusBadgeClass('success'),
            statusTextClass('warning'),
            statusSoftClass('info'),
            statusButtonClass('success'),
            statusInteractiveTextClass('danger'),
        ].join(' ');

        expect(all).not.toMatch(/emerald|rose-|amber|sky-/);
    });

    it('falls back to muted/foreground for unknown colors', () => {
        expect(statusBadgeClass('nope')).toContain('bg-muted');
        expect(statusTextClass(null)).toBe('text-foreground');
        expect(statusTextClass(undefined, 'text-muted-foreground')).toBe(
            'text-muted-foreground',
        );
        expect(statusInteractiveTextClass(null)).toBe('text-foreground');
    });

    it('builds solid buttons with token + foreground + hover + ring', () => {
        expect(statusButtonClass('success')).toBe(
            'bg-success text-success-foreground hover:bg-success/90 focus-visible:ring-success/20',
        );
        // default (e.g. primary) falls back to the primary button.
        expect(statusButtonClass('primary')).toContain('bg-primary');
    });

    it('interactive text includes a matching focus variant as static literals', () => {
        expect(statusInteractiveTextClass('success')).toBe(
            'text-success focus:text-success',
        );
    });

    it('resolves solid fill classes for progress bars/rings, falling back to primary', () => {
        expect(statusFillClass('danger')).toBe('bg-destructive');
        expect(statusFillClass('warning')).toBe('bg-warning');
        expect(statusFillClass('gray')).toBe('bg-muted-foreground');
        expect(statusFillClass('nope')).toBe('bg-primary');
        expect(statusFillClass(undefined)).toBe('bg-primary');
    });
});
