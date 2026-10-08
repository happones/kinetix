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
const canLeaveFirstStep = (values: Record<string, unknown>) => {
    const wrapper = mount(KinetixFormWizard, {
        props: {
            comp: {
                type: 'wizard',
                schema: [
                    {
                        type: 'wizard-step',
                        heading: 'Account',
                        schema: [
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
});
