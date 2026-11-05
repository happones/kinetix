import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

const state = vi.hoisted(() => ({ props: {} as Record<string, unknown> }));
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: state.props }) }));

import KinetixSpotlightTrigger from '@/components/KinetixSpotlightTrigger.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: { en: { kinetix: { spotlight_placeholder: 'Search…' } } },
});

describe('KinetixSpotlightTrigger', () => {
    it('dispatches the kinetix:spotlight event on click', async () => {
        const handler = vi.fn();
        window.addEventListener('kinetix:spotlight', handler);

        const wrapper = mount(KinetixSpotlightTrigger, {
            global: { plugins: [i18n] },
        });
        await wrapper.find('button').trigger('click');

        expect(handler).toHaveBeenCalledTimes(1);
        window.removeEventListener('kinetix:spotlight', handler);
    });

    it('shows the search placeholder and a shortcut hint', () => {
        const wrapper = mount(KinetixSpotlightTrigger, {
            global: { plugins: [i18n] },
        });
        expect(wrapper.text()).toContain('Search…');
        expect(wrapper.find('kbd').exists()).toBe(true);
    });

    it('renders nothing while the palette is turned off', () => {
        state.props = {
            kinetix_config: { spotlight: { min_chars: 2, enabled: false } },
        };

        try {
            const wrapper = mount(KinetixSpotlightTrigger, {
                global: { plugins: [i18n] },
            });
            expect(wrapper.find('button').exists()).toBe(false);
        } finally {
            state.props = {};
        }
    });
});
