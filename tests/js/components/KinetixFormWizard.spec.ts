import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));

import KinetixFormWizard from '@/components/KinetixFormWizard.vue';
import KinetixWizard from '@/components/KinetixWizard.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: { en: {} },
});

const field = (name: string, extra: Record<string, unknown> = {}) => ({
    type: 'text-input',
    name,
    label: name,
    columnSpan: 'full',
    ...extra,
});

/** Whether the first step lets the user move on, for these values. */
const canLeaveFirstStep = (
    values: Record<string, unknown>,
    extra: Record<string, unknown>[] = [],
) => {
    const wrapper = mount(KinetixFormWizard, {
        props: {
            comp: {
                type: 'wizard',
                schema: [
                    {
                        type: 'wizard-step',
                        heading: 'Account',
                        schema: [
                            ...extra,
                            field('type'),
                            field('company', {
                                isRequired: true,
                                conditions: {
                                    visible: {
                                        field: 'type',
                                        operator: 'equals',
                                        value: 'company',
                                    },
                                },
                            }),
                            field('reason', {
                                conditions: {
                                    required: {
                                        field: 'type',
                                        operator: 'equals',
                                        value: 'other',
                                    },
                                },
                            }),
                        ],
                    },
                    { type: 'wizard-step', heading: 'Done', schema: [] },
                ],
            },
            values,
            errors: {},
        },
        global: { plugins: [i18n] },
    });

    const beforeNext = wrapper
        .findComponent(KinetixWizard)
        .props('beforeNext') as (index: number) => boolean;

    return beforeNext(0);
};

// The step guard walked raw `isRequired` flags: a required field hidden by
// its condition blocked "Next" with no way to fill it, and `requiredWhen`
// never blocked at all.
describe('KinetixFormWizard step guard', () => {
    it('ignores a required field its condition hides', () => {
        expect(canLeaveFirstStep({ type: 'person' })).toBe(true);
    });

    it('blocks on a required field while it is shown', () => {
        expect(canLeaveFirstStep({ type: 'company' })).toBe(false);
        expect(canLeaveFirstStep({ type: 'company', company: 'Acme' })).toBe(
            true,
        );
    });

    it('blocks on a requiredWhen field while its condition holds', () => {
        expect(canLeaveFirstStep({ type: 'other' })).toBe(false);
        expect(canLeaveFirstStep({ type: 'other', reason: 'Because' })).toBe(
            true,
        );
    });

    // The guard walked into the items' schema and looked for `qty` among the
    // form's own values: "Next" never unblocked.
    it('counts a repeater by its items, not its sub-fields', () => {
        const items = {
            type: 'repeater',
            name: 'items',
            minItems: 2,
            schema: [field('qty', { isRequired: true })],
        };

        expect(
            canLeaveFirstStep(
                { type: 'person', items: [{ qty: '3' }, { qty: '' }] },
                [items],
            ),
        ).toBe(true);
        expect(
            canLeaveFirstStep({ type: 'person', items: [{ qty: '3' }] }, [
                items,
            ]),
        ).toBe(false);
        expect(
            canLeaveFirstStep({ type: 'person', lines: [] }, [
                {
                    type: 'table-repeater',
                    name: 'lines',
                    isRequired: true,
                    schema: [field('sku', { isRequired: true })],
                },
            ]),
        ).toBe(false);
    });
});
