import { describe, expect, it } from 'vitest';
import { useKinetixWizardStatus } from '@/composables/useKinetixWizardStatus';

const setup = (over: Partial<Record<string, any>> = {}) =>
    useKinetixWizardStatus({
        current: () => over.current ?? 1,
        maxReached: () => over.maxReached ?? 1,
        errorSteps: () => over.errorSteps ?? [],
        linear: () => over.linear ?? true,
    });

describe('useKinetixWizardStatus', () => {
    it('reports completion status relative to the current step', () => {
        const s = setup({ current: 1 });
        expect(s.statusOf(0)).toBe('complete');
        expect(s.statusOf(1)).toBe('active');
        expect(s.statusOf(2)).toBe('upcoming');
    });

    it('marks errored steps and keeps them reachable under linear gating', () => {
        const s = setup({ current: 0, maxReached: 0, errorSteps: [3] });
        expect(s.hasError(3)).toBe(true);
        // Step 2 is past maxReached and not errored → disabled.
        expect(s.stepDisabled(2)).toBe(true);
        // Step 3 is errored → reachable even though it's ahead.
        expect(s.stepDisabled(3)).toBe(false);
    });

    it('does not gate steps when linear is off', () => {
        const s = setup({ current: 0, maxReached: 0, linear: false });
        expect(s.stepDisabled(5)).toBe(false);
    });

    it('derives the indicator fill class from status, error, and color', () => {
        const s = setup({ current: 1, errorSteps: [0] });
        expect(s.indicatorClass({ label: 'a' } as any, 0)).toContain(
            'bg-destructive',
        );
        expect(s.indicatorClass({ label: 'b' } as any, 2)).toContain(
            'text-muted-foreground',
        );
        expect(s.indicatorClass({ label: 'c' } as any, 1)).toContain(
            'bg-primary',
        );
    });

    it('keys a step by its explicit key or falls back to the index', () => {
        const s = setup();
        expect(s.stepKey({ label: 'a', key: 'intro' } as any, 4)).toBe('intro');
        expect(s.stepKey({ label: 'b' } as any, 4)).toBe('4');
    });
});
