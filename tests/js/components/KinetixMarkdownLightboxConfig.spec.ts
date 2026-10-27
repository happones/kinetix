import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import KinetixMarkdownContent from '@/components/KinetixMarkdownContent.vue';
import { i18n } from './i18n';

const page = reactive({
    props: { kinetix_config: { lightbox: { markdown: true } } },
});

vi.mock('@inertiajs/vue3', () => ({ usePage: () => page }));

enableAutoUnmount(afterEach);

afterEach(() => {
    page.props.kinetix_config.lightbox.markdown = true;
});

const mountContent = (props: Record<string, unknown> = {}) =>
    mount(KinetixMarkdownContent, {
        global: { plugins: [i18n] },
        props: { html: '<img src="/a.png" alt="A">', ...props },
    });

const role = (wrapper: ReturnType<typeof mountContent>) =>
    wrapper.find('img').attributes('role');

describe('KinetixMarkdownContent — kinetix.lightbox.markdown', () => {
    it('opens images in the lightbox by default', async () => {
        const wrapper = mountContent();
        await flushPromises();

        expect(role(wrapper)).toBe('button');
    });

    it('leaves images inert when the app turns it off', async () => {
        page.props.kinetix_config.lightbox.markdown = false;

        const wrapper = mountContent();
        await flushPromises();

        expect(role(wrapper)).toBeUndefined();
    });

    it('lets the prop win over the app-wide switch, both ways', async () => {
        page.props.kinetix_config.lightbox.markdown = false;
        const on = mountContent({ lightbox: true });

        await flushPromises();
        expect(role(on)).toBe('button');

        page.props.kinetix_config.lightbox.markdown = true;
        const off = mountContent({ lightbox: false });

        await flushPromises();
        expect(role(off)).toBeUndefined();
    });

    it('follows the switch when it changes', async () => {
        const wrapper = mountContent();
        await flushPromises();

        page.props.kinetix_config.lightbox.markdown = false;
        await flushPromises();

        expect(role(wrapper)).toBeUndefined();
    });
});
