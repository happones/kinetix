import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import { createI18n } from 'vue-i18n';
import KinetixFormTabs from '@/components/KinetixFormTabs.vue';

const i18n = createI18n({ legacy: false, locale: 'en', messages: { en: {} } });

const tabs = [
    {
        type: 'tab',
        heading: 'Profile',
        schema: [{ type: 'text-input', name: 'name', label: 'Name' }],
    },
    {
        type: 'tab',
        heading: 'Contact',
        schema: [{ type: 'text-input', name: 'email', label: 'Email' }],
    },
];

const mountTabs = (errors: Record<string, string> = {}) =>
    mount(KinetixFormTabs, {
        props: { tabs, values: {}, errors },
        global: { plugins: [i18n] },
    });

describe('KinetixFormTabs', () => {
    it('marks a tab whose field has an error', () => {
        const wrapper = mountTabs({ email: 'Required' });
        const triggers = wrapper.findAll('[role="tab"]');

        expect(triggers[0].attributes('aria-invalid')).toBeUndefined();
        expect(triggers[1].attributes('aria-invalid')).toBe('true');
    });

    it('switches to the first tab holding an error', async () => {
        const wrapper = mountTabs();
        // Starts on the first tab.
        expect(
            wrapper.find('[role="tab"][aria-selected="true"]').text(),
        ).toContain('Profile');

        await wrapper.setProps({ errors: { email: 'Required' } });
        await nextTick();

        expect(
            wrapper.find('[role="tab"][aria-selected="true"]').text(),
        ).toContain('Contact');
    });

    it('does not steal focus from the active tab when its own field errors', async () => {
        const wrapper = mountTabs();
        await wrapper.setProps({ errors: { name: 'Required' } });
        await nextTick();

        // Error is on the already-active first tab — stay put.
        expect(
            wrapper.find('[role="tab"][aria-selected="true"]').text(),
        ).toContain('Profile');
    });
});
