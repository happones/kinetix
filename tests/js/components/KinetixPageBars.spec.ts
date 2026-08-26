import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: {} }),
    router: { get: vi.fn(), visit: vi.fn(), reload: vi.fn() },
}));

import KinetixPageFooter from '@/components/KinetixPageFooter.vue';
import KinetixPageHeader from '@/components/KinetixPageHeader.vue';

/**
 * The page action bars. Both render through the shared `KinetixActionBar`, so
 * these specs pin two things: that the footer is a real peer of the header
 * (same actions, same confirmation, same pending pipeline) and that extracting
 * the shared bar did not change what the header renders.
 *
 * Neither bar knows anything about page content — that independence is the
 * point, and it is why they take only `actions`.
 */
const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: {} } },
});

const action = (over: Record<string, unknown> = {}) => ({
    name: 'save',
    label: 'Save changes',
    viewType: 'button',
    shouldOpenInNewTab: false,
    color: null,
    shouldClose: false,
    shouldMarkAsRead: false,
    shouldMarkAsUnread: false,
    ...over,
});

const mountFooter = (
    props: Record<string, unknown> = {},
    slots: Record<string, string> = {},
) =>
    mount(KinetixPageFooter, {
        props: { actions: [action()], ...props },
        slots,
        global: { plugins: [i18n] },
    });

describe('KinetixPageFooter', () => {
    it('renders the actions it is given, and nothing else', () => {
        const w = mountFooter({
            actions: [
                action({ name: 'cancel', label: 'Cancel', color: 'gray' }),
                action({ icon: 'check' }),
            ],
        });

        const buttons = w.findAll('button');
        expect(buttons).toHaveLength(2);
        expect(w.text()).toContain('Cancel');
        expect(w.text()).toContain('Save changes');
        // No heading of its own: the footer is an action bar, not a section.
        expect(w.find('h1').exists()).toBe(false);
    });

    it('is plain by default and gains the pinned chrome only with `sticky`', () => {
        expect(mountFooter().html()).not.toContain('sticky');

        const pinned = mountFooter({ sticky: true });
        const wrapper = pinned.element as HTMLElement;
        expect(wrapper.className).toContain('sticky');
        expect(wrapper.className).toContain('bottom-0');
        // Solid background + top border, or content scrolls visibly under it.
        expect(wrapper.className).toContain('bg-background');
        expect(wrapper.className).toContain('border-t');
    });

    it('stacks full width on mobile with the primary action on top', () => {
        const w = mountFooter();

        // `flex-col-reverse` puts the LAST action first on a narrow screen, and
        // no `items-center` at that width is what lets the buttons stretch.
        const bar = w
            .findAll('div')
            .find((d) => d.classes().includes('flex-col-reverse'));
        expect(bar).toBeDefined();
        expect(bar!.classes()).toContain('w-full');
        expect(bar!.classes()).toContain('sm:flex-row');
    });

    it('renders the left slot only when something is in it', () => {
        expect(mountFooter().text()).not.toContain('All changes saved');

        const w = mountFooter({}, { 'before-actions': 'All changes saved' });
        expect(w.text()).toContain('All changes saved');
    });

    it('routes a confirmed action through the shared modal instead of firing it', async () => {
        const fired = vi.fn();
        window.addEventListener('kinetix:archive', fired);

        const w = mountFooter({
            actions: [
                action({
                    name: 'archive',
                    label: 'Archive',
                    dispatchEvent: 'archive',
                    requiresConfirmation: true,
                    modalHeading: 'Archive this record?',
                }),
            ],
        });

        await w.find('button').trigger('click');

        expect(fired).not.toHaveBeenCalled();
        expect(document.body.textContent).toContain('Archive this record?');

        window.removeEventListener('kinetix:archive', fired);
        document.body.innerHTML = '';
    });

    it('does not bind action shortcuts by default, so it cannot double-bind the header ones', async () => {
        const w = mountFooter({
            actions: [action({ dispatchEvent: 'save', shortcut: 'mod+s' })],
        });
        const fired = vi.fn();
        window.addEventListener('kinetix:save', fired);

        window.dispatchEvent(
            new KeyboardEvent('keydown', { key: 's', ctrlKey: true }),
        );
        await w.vm.$nextTick();

        expect(fired).not.toHaveBeenCalled();
        window.removeEventListener('kinetix:save', fired);
        w.unmount();
    });
});

describe('KinetixPageHeader — unchanged by the shared bar extraction', () => {
    const mountHeader = (props: Record<string, unknown> = {}) =>
        mount(KinetixPageHeader, {
            props: { heading: 'Products', ...props },
            global: { plugins: [i18n] },
        });

    it('still renders its heading, description and actions', () => {
        const w = mountHeader({
            description: 'Everything you sell.',
            actions: [
                action({ name: 'new', label: 'New product', icon: 'plus' }),
            ],
        });

        expect(w.find('h1').text()).toBe('Products');
        expect(w.text()).toContain('Everything you sell.');
        expect(w.find('button').text()).toContain('New product');
        expect(w.find('button svg').exists()).toBe(true);
    });

    it('keeps both of its slots', () => {
        const w = mount(KinetixPageHeader, {
            props: { heading: 'Products' },
            slots: {
                'before-actions': '<span>left</span>',
                default: '<span>right</span>',
            },
            global: { plugins: [i18n] },
        });

        expect(w.text()).toContain('left');
        expect(w.text()).toContain('right');
    });

    it('omits the title block entirely when it has neither heading nor description', () => {
        const w = mount(KinetixPageHeader, {
            props: { actions: [action()] },
            global: { plugins: [i18n] },
        });

        expect(w.find('h1').exists()).toBe(false);
        expect(w.find('button').text()).toContain('Save changes');
    });
});
