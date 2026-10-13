import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';
import KinetixKeyValue from '@/components/KinetixKeyValue.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    missing: (_l: string, key: string) => key.replace('kinetix.', ''),
    messages: { en: {} },
});

describe('KinetixKeyValue with fixed keys', () => {
    it('shows a labelled input per key, with no rows to add or remove', async () => {
        const wrapper = mount(KinetixKeyValue, {
            props: {
                id: 'meta',
                keys: ['title', 'color'],
                value: { title: 'Hi' },
            },
            global: { plugins: [i18n] },
        });

        expect(wrapper.findAll('label').map((l) => l.text())).toEqual([
            'title',
            'color',
        ]);
        expect(wrapper.get('#meta-title').element).toHaveProperty(
            'value',
            'Hi',
        );
        expect(wrapper.findAll('button')).toHaveLength(0);

        await wrapper.get('#meta-color').setValue('red');

        expect(wrapper.emitted('update:value')?.at(-1)).toEqual([
            { title: 'Hi', color: 'red' },
        ]);
    });
});
