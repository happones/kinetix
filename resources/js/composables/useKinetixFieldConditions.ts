import type { KinetixFieldCondition } from '@/types/kinetix';

/**
 * Client-side mirror of PHP's `FieldCondition::passes()` — evaluates a
 * serialized condition against the current form values so `KinetixForm` can
 * show/hide/disable/require fields live, with no server round-trip. The server
 * re-checks the same condition on submit, so this is a UX layer, not the guard.
 */
function passes(
    condition: KinetixFieldCondition,
    values: Record<string, unknown>,
): boolean {
    const actual = values[condition.field];
    const target = condition.value;

    const looseEq = (a: unknown, b: unknown): boolean =>
        a === b || String(a) === String(b);

    const isTruthy = (v: unknown): boolean =>
        !(
            v === null ||
            v === undefined ||
            v === false ||
            v === 0 ||
            v === '0' ||
            v === '' ||
            (Array.isArray(v) && v.length === 0)
        );

    const isBlank = (v: unknown): boolean =>
        v === null ||
        v === undefined ||
        v === '' ||
        (Array.isArray(v) && v.length === 0);

    switch (condition.operator) {
        case 'equals':
            return looseEq(actual, target);
        case 'notEquals':
            return !looseEq(actual, target);
        case 'in':
            return (
                Array.isArray(target) && target.some((t) => looseEq(t, actual))
            );
        case 'notIn':
            return !(
                Array.isArray(target) && target.some((t) => looseEq(t, actual))
            );
        case 'truthy':
            return isTruthy(actual);
        case 'falsy':
            return !isTruthy(actual);
        case 'filled':
            return !isBlank(actual);
        case 'blank':
            return isBlank(actual);
        default:
            return true;
    }
}

/**
 * Resolve a field's conditional effects against the current form values.
 * `conditions` is the serialized `{ visible?, hidden?, required?, disabled? }`
 * map from `FormFieldData`. Returns the effective flags; a field with no
 * conditions is visible, enabled and keeps its static `required`.
 */
export function useKinetixFieldConditions() {
    const resolve = (
        comp: {
            conditions?: Record<string, KinetixFieldCondition> | null;
        },
        values: Record<string, unknown>,
    ): { visible: boolean; disabled: boolean; required: boolean | null } => {
        const conditions = comp.conditions;

        if (!conditions) {
            return { visible: true, disabled: false, required: null };
        }

        let visible = true;

        if (conditions.visible && !passes(conditions.visible, values)) {
            visible = false;
        }

        if (conditions.hidden && passes(conditions.hidden, values)) {
            visible = false;
        }

        const disabled = conditions.disabled
            ? passes(conditions.disabled, values)
            : false;

        // `required` here is the CONDITIONAL requirement only (null = leave the
        // field's static `isRequired` untouched).
        const required = conditions.required
            ? passes(conditions.required, values)
            : null;

        return { visible, disabled, required };
    };

    return { resolve, passes };
}
