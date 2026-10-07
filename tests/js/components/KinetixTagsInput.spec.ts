import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';
import KinetixTagsInput from '@/components/KinetixTagsInput.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: { en: { kinetix: { tag_remove: 'Remove tag' } } },
});

const mountTags = (options: { props: Record<string, unknown> }) =>
    mount(KinetixTagsInput, { ...options, global: { plugins: [i18n] } });

describe('KinetixTagsInput', () => {
    it('renders the existing tags', () => {
        const wrapper = mountTags({
            props: { value: ['php', 'vue'] },
        });

        expect(wrapper.text()).toContain('php');
        expect(wrapper.text()).toContain('vue');
    });

    it('adds a tag on Enter and emits the updated array', async () => {
        const wrapper = mountTags({ props: { value: [] } });
        const input = wrapper.find('input');

        await input.setValue('design');
        await input.trigger('keydown', { key: 'Enter' });

        expect(wrapper.emitted('update:value')?.[0]).toEqual([['design']]);
    });

    it('does not add a duplicate tag', async () => {
        const wrapper = mountTags({ props: { value: ['php'] } });
        const input = wrapper.find('input');

        await input.setValue('php');
        await input.trigger('keydown', { key: 'Enter' });

        expect(wrapper.emitted('update:value')).toBeUndefined();
    });

    it('removes a tag when its remove button is clicked', async () => {
        const wrapper = mountTags({
            props: { value: ['php', 'vue'] },
        });

        await wrapper.findAll('button')[0].trigger('click');

        expect(wrapper.emitted('update:value')?.[0]).toEqual([['vue']]);
    });

    it('removes the last tag on Backspace when the input is empty', async () => {
        const wrapper = mountTags({
            props: { value: ['a', 'b'] },
        });

        await wrapper.find('input').trigger('keydown', { key: 'Backspace' });

        expect(wrapper.emitted('update:value')?.[0]).toEqual([['a']]);
    });

    it('hides remove buttons when disabled', () => {
        const wrapper = mountTags({
            props: { value: ['php'], disabled: true },
        });

        expect(wrapper.findAll('button')).toHaveLength(0);
    });

    it('names each remove button after its tag, on a 24px target', () => {
        const wrapper = mountTags({ props: { value: ['php', 'vue'] } });
        const remove = wrapper.findAll('button');

        expect(remove.map((b) => b.attributes('aria-label'))).toEqual([
            'Remove tag: php',
            'Remove tag: vue',
        ]);
        expect(remove[0].classes()).toContain('size-6');
    });
});
