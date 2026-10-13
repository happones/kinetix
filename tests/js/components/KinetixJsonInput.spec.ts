import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';
import KinetixJsonInput from '@/components/KinetixJsonInput.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    missing: (_l: string, key: string) => key.replace('kinetix.', ''),
    messages: {
        en: { kinetix: { json_breaks_at: 'line {line}, column {column}' } },
    },
});

const mountJson = (value: string) =>
    mount(KinetixJsonInput, {
        props: { id: 'settings', value },
        global: { plugins: [i18n] },
    });

describe('KinetixJsonInput', () => {
    it('says the JSON holds', () => {
        expect(mountJson('{"a": 1}').text()).toContain('json_valid');
    });

    it('says where the JSON breaks', () => {
        // The value of "b" is missing: the closing brace on line 4 is wrong.
        const wrapper = mountJson('{\n  "a": 1,\n  "b": \n}');

        expect(wrapper.text()).toContain('line 4, column 1');
        expect(wrapper.text()).not.toContain('json_valid');
        expect(mountJson('[1, 2').text()).toContain('line 1, column 6');
    });

    it('formats valid JSON, and only valid JSON', async () => {
        const wrapper = mountJson('{"a":1,"b":[1,2]}');

        await wrapper.get('button').trigger('click');

        expect(wrapper.emitted('update:value')?.[0]).toEqual([
            '{\n  "a": 1,\n  "b": [\n    1,\n    2\n  ]\n}',
        ]);
        expect(
            mountJson('{"a":').get('button').attributes('disabled'),
        ).toBeDefined();
    });

    it('turns off autocorrect and keeps the field wiring on the text area', () => {
        const wrapper = mount(KinetixJsonInput, {
            props: { value: '' },
            attrs: { 'aria-invalid': 'true' },
            global: { plugins: [i18n] },
        });
        const area = wrapper.get('textarea');

        expect(area.attributes('spellcheck')).toBe('false');
        expect(area.attributes('aria-invalid')).toBe('true');
    });
});
