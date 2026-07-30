import { statusButtonClass } from '@/composables/useStatusColor';
import type { KinetixWizardStep } from '@/types/kinetix';

export interface UseKinetixWizardStatusOptions {
    current: () => number;
    maxReached: () => number;
    errorSteps: () => number[];
    linear: () => boolean;
}

export interface UseKinetixWizardStatus {
    statusOf: (index: number) => 'complete' | 'active' | 'upcoming';
    hasError: (index: number) => boolean;
    stepDisabled: (index: number) => boolean;
    indicatorClass: (step: KinetixWizardStep, index: number) => string;
    stepKey: (step: KinetixWizardStep, index: number) => string;
}

/**
 * Per-step status derivations shared by the wizard and every indicator variant:
 * completion state, error marking, linear-gating, and the indicator fill class.
 * Kept in one place so the container and the extracted indicator components
 * agree on a step's appearance.
 */
export function useKinetixWizardStatus(
    options: UseKinetixWizardStatusOptions,
): UseKinetixWizardStatus {
    const statusOf = (index: number): 'complete' | 'active' | 'upcoming' => {
        if (index < options.current()) {
            return 'complete';
        }

        return index === options.current() ? 'active' : 'upcoming';
    };

    const hasError = (index: number): boolean =>
        options.errorSteps().includes(index);

    // Disabled under linear gating once past the furthest-reached step — except
    // errored steps, which stay reachable so the user can jump to a failure.
    const stepDisabled = (index: number): boolean =>
        options.linear() && index > options.maxReached() && !hasError(index);

    // Neutral while upcoming, otherwise the step's own status color (or primary).
    // Computed here since a per-step color can't be a static Tailwind class.
    const indicatorClass = (step: KinetixWizardStep, index: number): string => {
        if (hasError(index)) {
            return 'bg-destructive text-white ring-2 ring-destructive/30';
        }

        if (statusOf(index) === 'upcoming') {
            return 'border border-border bg-card text-muted-foreground';
        }

        return step.color
            ? statusButtonClass(step.color)
            : 'bg-primary text-primary-foreground';
    };

    const stepKey = (step: KinetixWizardStep, index: number): string =>
        step.key ?? String(index);

    return { statusOf, hasError, stepDisabled, indicatorClass, stepKey };
}
