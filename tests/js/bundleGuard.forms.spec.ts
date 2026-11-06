import path from 'node:path';
import { describe, expect, it } from 'vitest';
import {
    importChain,
    JS_ROOT,
    staticClosure,
} from './support/staticImportGraph';

/**
 * Bundle guard for tables, forms and long lists. Whatever a component reaches
 * through STATIC imports ships in its chunk on every page that renders it, so
 * the heavy, optional parts must stay behind a dynamic import (a lazy entry in
 * the field/filter/modal-body loaders, or the virtualizer's on-demand engine).
 *
 * A failure prints the import chain that pulled the module in: break it by
 * moving the import into the matching loader, not by deleting this rule.
 */

const component = (name: string): string =>
    path.join(JS_ROOT, 'components', name);

/** Rules: a module matches by exact path, or a package by name/prefix. */
const file = (name: string) => (module: string) => module === component(name);
const pkg = (prefix: string) => (module: string) =>
    !path.isAbsolute(module) &&
    (module === prefix || module.startsWith(`${prefix}/`));
const pkgScope = (scope: string) => (module: string) =>
    !path.isAbsolute(module) && module.startsWith(`${scope}/`);

const DATE_PICKERS = [
    'KinetixCalendar.vue',
    'KinetixDatePicker.vue',
    'KinetixDateTimePicker.vue',
    'KinetixTimePicker.vue',
    'KinetixDateRangePicker.vue',
    'KinetixRangeCalendar.vue',
    'KinetixMonthPicker.vue',
    'KinetixYearPicker.vue',
    'KinetixWeekPicker.vue',
    'KinetixPeriodFilter.vue',
    'KinetixMaskedDateInput.vue',
];

const INPUT_CELLS = [
    'NumberInputCell.vue',
    'SelectInputCell.vue',
    'ToggleInputCell.vue',
    'TextInputCell.vue',
    'CheckboxInputCell.vue',
];

type Rule = { label: string; matches: (module: string) => boolean };

const rule = (label: string, matches: (module: string) => boolean): Rule => ({
    label,
    matches,
});

const GUARDS: Array<{ from: string; forbidden: Rule[] }> = [
    {
        from: 'KinetixTable.vue',
        forbidden: [
            rule('KinetixForm.vue', file('KinetixForm.vue')),
            rule('KinetixInfolist.vue', file('KinetixInfolist.vue')),
            rule('KinetixFormSchema.vue', file('KinetixFormSchema.vue')),
            ...DATE_PICKERS.map((name) => rule(name, file(name))),
            rule('KinetixAddressPicker.vue', file('KinetixAddressPicker.vue')),
            rule('@internationalized/date', pkg('@internationalized/date')),
            rule('@tanstack/*', pkgScope('@tanstack')),
            rule('KinetixCombobox.vue', file('KinetixCombobox.vue')),
            rule('@internationalized/number', pkg('@internationalized/number')),
            ...INPUT_CELLS.map((name) =>
                rule(`Table/cells/${name}`, file(`Table/cells/${name}`)),
            ),
        ],
    },
    {
        from: 'KinetixForm.vue',
        forbidden: [
            rule('KinetixMediaLibrary.vue', file('KinetixMediaLibrary.vue')),
            rule('KinetixRichEditor.vue', file('KinetixRichEditor.vue')),
            rule('KinetixFormWizard.vue', file('KinetixFormWizard.vue')),
            rule('KinetixWizard.vue', file('KinetixWizard.vue')),
            rule('KinetixCombobox.vue', file('KinetixCombobox.vue')),
            rule('KinetixSelect.vue', file('KinetixSelect.vue')),
            ...DATE_PICKERS.map((name) => rule(name, file(name))),
            rule('@internationalized/date', pkg('@internationalized/date')),
            rule('@tanstack/*', pkgScope('@tanstack')),
        ],
    },
    // The members screen carries the forms engine only for apps that declare
    // custom member fields: it arrives through the lazy member loaders.
    ...[
        'KinetixMemberList.vue',
        'KinetixMemberProvisioner.vue',
        'Members/KinetixMemberEditModal.vue',
    ].map((from) => ({
        from,
        forbidden: [
            rule('KinetixFormSchema.vue', file('KinetixFormSchema.vue')),
            rule('KinetixForm.vue', file('KinetixForm.vue')),
            rule(
                'Members/KinetixMemberFields.vue',
                file('Members/KinetixMemberFields.vue'),
            ),
            ...DATE_PICKERS.map((name) => rule(name, file(name))),
            rule('@internationalized/date', pkg('@internationalized/date')),
        ],
    })),
    ...[
        'Kanban/KanbanColumn.vue',
        'KinetixComments.vue',
        'KinetixMediaLibrary.vue',
    ].map((from) => ({
        from,
        forbidden: [
            rule('@tanstack/vue-virtual', pkg('@tanstack/vue-virtual')),
        ],
    })),
];

describe('bundle guard: tables, forms and lists', () => {
    it('resolves the import graph of the guarded components', () => {
        const closure = staticClosure(component('KinetixTable.vue'));

        // Sanity: the graph really walks (the table reaches its toolbar and
        // the shared primitives), so an empty closure can't pass every rule.
        expect(closure.has(component('Table/KinetixTableToolbar.vue'))).toBe(
            true,
        );
        expect(closure.has('vue')).toBe(true);
        expect(
            staticClosure(component('KinetixForm.vue')).has(
                component('Form/KinetixFormField.vue'),
            ),
        ).toBe(true);
    });

    for (const { from, forbidden } of GUARDS) {
        it.each(forbidden)(
            `${from} never statically reaches $label`,
            ({ matches }) => {
                const chain = importChain(component(from), matches);

                expect(
                    chain,
                    `Static import chain:\n  ${chain?.join('\n  -> ')}`,
                ).toBeNull();
            },
        );
    }
});
