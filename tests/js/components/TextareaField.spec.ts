import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import TextareaField from '@/components/Form/fields/TextareaField.vue';

/**
 * The shadcn textarea sizes itself to its content (`field-sizing-content`),
 * which makes the browser ignore `rows`; the requested rows become a minimum
 * height instead.
 */
describe('TextareaField rows', () => {
    it('turns rows() into a minimum height', () => {
        const wrapper = mount(TextareaField, {
            props: {
                comp: { name: 'notes', extraInputAttributes: { rows: '8' } },
                value: '',
            },
        });

        const textarea = wrapper.find('textarea');

        expect(textarea.attributes('rows')).toBe('8');
        expect(textarea.attributes('style')).toContain(
            'min-height: calc(8lh + 1rem + 2px)',
        );
    });

    it('leaves the default height alone without rows()', () => {
        const wrapper = mount(TextareaField, {
            props: { comp: { name: 'notes' }, value: '' },
        });

        expect(wrapper.find('textarea').attributes('style')).toBeUndefined();
    });
});
