import type { KinetixFieldCondition } from '@/types/kinetix';

type Value = unknown;

const isMap = (v: Value): v is Record<string, unknown> =>
    v !== null && typeof v === 'object' && !Array.isArray(v);

// The server reads an object keyed 0..n-1 as a list (that's what it decodes
// to), so it counts as one here too.
const isSequential = (v: Record<string, unknown>): boolean =>
    Object.keys(v).every((key, i) => key === String(i));

const isList = (v: Value): v is unknown[] | Record<string, unknown> =>
    Array.isArray(v) || (isMap(v) && isSequential(v));

const asList = (v: unknown[] | Record<string, unknown>): unknown[] =>
    Array.isArray(v) ? v : Object.values(v);

// PHP's trim(): ASCII whitespace only. A non-breaking space is text there,
// and must be here.
const trimAscii = (v: string): string =>
    v.replace(/^[ \t\n\r\0\x0B]+|[ \t\n\r\0\x0B]+$/g, '');

const text = (v: Value): string => {
    if (v === null || v === undefined) {
        return '';
    }

    if (typeof v === 'boolean') {
        return v ? '1' : '0';
    }

    return typeof v === 'string' || typeof v === 'number' ? String(v) : '';
};

const toBool = (v: Value): boolean => {
    if (v === null || v === undefined) {
        return false;
    }

    if (typeof v === 'boolean') {
        return v;
    }

    if (typeof v === 'number') {
        return v !== 0;
    }

    if (typeof v === 'string') {
        return !['', '0', 'false'].includes(trimAscii(v).toLowerCase());
    }

    if (isList(v) || isMap(v)) {
        return Object.keys(v).length > 0;
    }

    return true;
};

const isBlank = (v: Value): boolean =>
    v === null ||
    v === undefined ||
    (typeof v === 'string' && trimAscii(v) === '') ||
    ((isList(v) || isMap(v)) && Object.keys(v).length === 0);

const same = (a: Value, b: Value): boolean => {
    if (isList(a) || isList(b) || isMap(a) || isMap(b)) {
        return false;
    }

    if (typeof a === 'boolean' || typeof b === 'boolean') {
        return toBool(a) === toBool(b);
    }

    return text(a) === text(b);
};

const sameMembers = (a: unknown[], b: unknown[]): boolean => {
    const texts = (list: unknown[]) => list.map(text).sort();
    const [left, right] = [texts(a), texts(b)];

    return left.length === right.length && left.every((t, i) => t === right[i]);
};

// An expected list or map: the values it holds.
const expectedList = (v: Value): unknown[] | null =>
    isList(v) || isMap(v) ? asList(v) : null;

const matches = (actual: Value, expected: Value): boolean => {
    const members = expectedList(expected);

    if (isList(actual)) {
        const items = asList(actual);

        return members !== null
            ? sameMembers(items, members)
            : items.some((item) => same(item, expected));
    }

    if (isMap(actual)) {
        return false;
    }

    return members === null && same(actual, expected);
};

const isIn = (actual: Value, list: Value): boolean => {
    const members = expectedList(list);

    if (members === null) {
        return false;
    }

    const candidates = isList(actual)
        ? asList(actual)
        : isMap(actual)
          ? []
          : [actual];

    return candidates.some((candidate) =>
        members.some((item) => same(candidate, item)),
    );
};

/**
 * Client-side mirror of PHP's `FieldCondition::passes()` — evaluates a
 * serialized condition against the current form values so `KinetixForm` can
 * show/hide/disable/require fields live, with no server round-trip. The server
 * re-checks the same condition on submit, and the two must agree: a field
 * shown here but hidden there is dropped on save. The semantics (documented on
 * the PHP class) are pinned by `tests/js/fixtures/field-conditions.json`,
 * which both test suites run.
 */
function passes(
    condition: KinetixFieldCondition,
    values: Record<string, unknown>,
): boolean {
    const actual = values[condition.field];
    const target = condition.value;

    switch (condition.operator) {
        case 'equals':
            return matches(actual, target);
        case 'notEquals':
            return !matches(actual, target);
        case 'in':
            return isIn(actual, target);
        case 'notIn':
            return !isIn(actual, target);
        case 'truthy':
            return toBool(actual);
        case 'falsy':
            return !toBool(actual);
        case 'filled':
            return !isBlank(actual);
        case 'blank':
            return isBlank(actual);
        default:
            // The server refuses unknown operators outright; never show a
            // field on a condition it can't evaluate.
            return false;
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
