import { describe, expect, it } from 'vitest';
import {
    collectFieldNames,
    errorTargetsField,
    firstErroredField,
    schemaHasError,
} from '@/composables/useKinetixFormErrors';

// A schema mixing tabs, a wizard, sections and a repeater — the shapes the
// error helpers must traverse uniformly via `.schema`.
const schema = [
    {
        type: 'tabs',
        schema: [
            {
                type: 'tab',
                heading: 'Profile',
                schema: [{ type: 'text-input', name: 'name' }],
            },
            {
                type: 'tab',
                heading: 'Contact',
                schema: [
                    {
                        type: 'section',
                        schema: [{ type: 'text-input', name: 'email' }],
                    },
                ],
            },
        ],
    },
    {
        type: 'wizard',
        schema: [
            {
                type: 'wizard-step',
                schema: [{ type: 'text-input', name: 'company' }],
            },
            {
                type: 'wizard-step',
                schema: [{ type: 'repeater', name: 'line_items', schema: [] }],
            },
        ],
    },
];

describe('useKinetixFormErrors', () => {
    it('collects field names in declaration order across all nesting', () => {
        expect(collectFieldNames(schema)).toEqual([
            'name',
            'email',
            'company',
            'line_items',
        ]);
    });

    it('matches exact and nested/array error keys to a field', () => {
        expect(errorTargetsField('email', 'email')).toBe(true);
        expect(errorTargetsField('address.city', 'address')).toBe(true);
        expect(errorTargetsField('line_items.0.qty', 'line_items')).toBe(true);
        expect(errorTargetsField('emailAddress', 'email')).toBe(false);
    });

    it('detects whether a subtree contains an errored field', () => {
        const tab = schema[0].schema[1]; // Contact tab (holds email)
        expect(schemaHasError(tab.schema, ['email'])).toBe(true);
        expect(schemaHasError(tab.schema, ['name'])).toBe(false);
        expect(schemaHasError(schema, [])).toBe(false);
    });

    it('finds the first errored field in DOM order', () => {
        expect(firstErroredField(schema, ['email', 'name'])).toBe('name');
        expect(firstErroredField(schema, ['line_items.0.qty'])).toBe(
            'line_items',
        );
        expect(firstErroredField(schema, ['missing'])).toBeNull();
    });
});
