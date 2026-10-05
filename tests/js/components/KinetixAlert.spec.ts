import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, ref } from 'vue';
import { createI18n } from 'vue-i18n';

const pageProps: Record<string, unknown> = { auth: { user: { id: 1 } } };
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: pageProps }) }));

const announce = vi.fn();
vi.mock('@/composables/useKinetixAnnounce', () => ({
    useKinetixAnnounce: () => ({ announce }),
}));

import KinetixAlert from '@/components/KinetixAlert.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en: {
            kinetix: {
                alert_dismiss: 'Dismiss',
                alert_hide: 'Hide for now',
                alert_dont_show_again: 'Don’t show again',
                alert_dismissed: 'Message dismissed.',
                alert_hidden: 'Message hidden for now.',
                alert_label_success: 'Success:',
                alert_label_danger: 'Error:',
                alert_label_warning: 'Warning:',
                alert_label_info: 'Information:',
            },
        },
    },
});

const mountIt = (props: Record<string, unknown> = {}, slots = {}) =>
    mount(KinetixAlert, {
        props: { title: 'Heads up', description: 'Body text', ...props },
        slots,
        global: { plugins: [i18n] },
        attachTo: document.body,
    });

const surface = (w: ReturnType<typeof mountIt>) =>
    w.find('[data-slot="alert"]');
const button = (w: ReturnType<typeof mountIt>, label: string) =>
    w.findAll('button').find((b) => b.attributes('aria-label') === label);

describe('KinetixAlert', () => {
    beforeEach(() => {
        sessionStorage.clear();
        localStorage.clear();
        announce.mockClear();
        document.body.innerHTML = '';
    });

    it('colors the surface, not the text, for every status', () => {
        const w = mountIt({ color: 'warning' });

        expect(surface(w).classes()).toEqual(
            expect.arrayContaining(['bg-warning/10', 'border-warning/30']),
        );
        // Title/body stay on foreground tokens (contrast in both themes).
        expect(w.find('[data-slot="alert-description"]').classes()).toContain(
            'text-foreground/80',
        );
        w.unmount();
    });

    it('offers the outline and accent surfaces', () => {
        const outline = mountIt({ color: 'success', variant: 'outline' });
        expect(surface(outline).classes()).toContain('border-success/60');
        outline.unmount();

        const accent = mountIt({ color: 'info', variant: 'accent' });
        expect(surface(accent).classes()).toEqual(
            expect.arrayContaining(['border-l-4', 'border-l-info']),
        );
        accent.unmount();
    });

    it('a solid fill sets its own text color and everything on it inherits', () => {
        const w = mountIt({
            color: 'success',
            variant: 'solid',
            dismissible: true,
        });

        expect(surface(w).classes()).toEqual(
            expect.arrayContaining(['bg-success', 'text-success-foreground']),
        );
        expect(w.find('[data-slot="alert"] > span svg').classes()).toContain(
            'text-current',
        );
        expect(
            w.find('[data-slot="alert-description"]').classes(),
        ).not.toContain('text-foreground/80');
        // The close button keeps the fill's text color instead of accent gray.
        expect(button(w, 'Hide for now')?.classes()).toContain('text-current');
        w.unmount();
    });

    it('a solid danger fill dims in dark mode to carry white text', () => {
        const w = mountIt({ color: 'danger', variant: 'solid' });

        expect(surface(w).classes()).toEqual(
            expect.arrayContaining([
                'bg-destructive',
                'text-white',
                'dark:bg-destructive/60',
            ]),
        );
        w.unmount();
    });

    it('never relies on color alone: decorative icon + hidden status prefix', () => {
        const w = mountIt({ color: 'danger' });

        const icon = w.find('[data-slot="alert"] > span[aria-hidden="true"]');
        expect(icon.exists()).toBe(true);
        expect(icon.find('svg').exists()).toBe(true);
        expect(w.find('[data-slot="alert-title"] .sr-only').text()).toBe(
            'Error:',
        );
        w.unmount();
    });

    it('lets the status prefix be replaced or dropped', () => {
        const custom = mountIt({ color: 'warning', srLabel: 'Careful:' });
        expect(custom.find('.sr-only').text()).toBe('Careful:');
        custom.unmount();

        const none = mountIt({ color: 'warning', srLabel: false });
        expect(none.find('.sr-only').exists()).toBe(false);
        none.unmount();
    });

    it('maps the role from the color: danger is assertive, the rest polite', () => {
        const danger = mountIt({ color: 'danger' });
        expect(surface(danger).attributes('role')).toBe('alert');
        danger.unmount();

        const info = mountIt({ color: 'info' });
        expect(surface(info).attributes('role')).toBe('status');
        info.unmount();

        const none = mountIt({ role: 'none' });
        expect(surface(none).attributes('role')).toBeUndefined();
        none.unmount();
    });

    it('names a region landmark by its title', () => {
        const w = mountIt({ role: 'region' });
        const title = w.find('[data-slot="alert-title"]');

        expect(surface(w).attributes('role')).toBe('region');
        expect(surface(w).attributes('aria-labelledby')).toBe(
            title.attributes('id'),
        );
        w.unmount();
    });

    it('renders the title as a heading only when asked', () => {
        const plain = mountIt();
        expect(plain.find('[data-slot="alert-title"]').element.tagName).toBe(
            'P',
        );
        plain.unmount();

        const heading = mountIt({ headingLevel: 3 });
        expect(heading.find('[data-slot="alert-title"]').element.tagName).toBe(
            'H3',
        );
        heading.unmount();
    });

    it('renders an actions slot', () => {
        const w = mountIt(
            {},
            { actions: () => h('a', { href: '/billing' }, 'Upgrade') },
        );

        expect(w.find('a[href="/billing"]').text()).toBe('Upgrade');
        w.unmount();
    });

    it('hides on close, announces it, and labels the button by how long it lasts', async () => {
        const w = mountIt({ dismissible: true });

        await button(w, 'Hide for now')?.trigger('click');
        await flushPromises();

        expect(surface(w).exists()).toBe(false);
        expect(w.emitted('dismiss')?.[0]).toEqual(['hide']);
        expect(w.emitted('update:open')?.[0]).toEqual([false]);
        expect(announce).toHaveBeenCalledWith('Message hidden for now.');
        w.unmount();
    });

    it('a `session` close survives a remount in the same tab', async () => {
        const props = {
            dismissible: true,
            dismissMode: 'session',
            dismissKey: 'tips',
        };
        const first = mountIt(props);
        await button(first, 'Hide for now')?.trigger('click');
        await flushPromises();
        first.unmount();

        const second = mountIt(props);
        expect(surface(second).exists()).toBe(false);
        second.unmount();
    });

    it('a `permanent` close goes through persist, and re-opens if it fails', async () => {
        const persist = vi.fn().mockRejectedValueOnce(new Error('500'));
        const w = mountIt({
            dismissible: true,
            dismissMode: 'permanent',
            dismissKey: 'profile',
            persist,
        });

        await button(w, 'Dismiss')?.trigger('click');
        await flushPromises();

        expect(persist).toHaveBeenCalledWith('profile');
        expect(surface(w).exists()).toBe(true);
        expect(w.emitted('dismissError')).toHaveLength(1);
        w.unmount();
    });

    it('`dontShowAgain` pairs a temporary ✕ with a lasting link', async () => {
        const persist = vi.fn().mockResolvedValue(undefined);
        const w = mountIt({
            dismissible: true,
            dismissMode: 'session',
            dismissKey: 'beta',
            dontShowAgain: true,
            persist,
        });

        expect(button(w, 'Hide for now')).toBeTruthy();

        const link = w
            .findAll('button')
            .find((b) => b.text() === 'Don’t show again');
        await link?.trigger('click');
        await flushPromises();

        expect(persist).toHaveBeenCalledWith('beta');
        expect(w.emitted('dismiss')?.[0]).toEqual(['permanent']);
        expect(announce).toHaveBeenCalledWith('Message dismissed.');
        w.unmount();
    });

    it('moves focus to the next control instead of dropping it on <body>', async () => {
        const Host = defineComponent({
            components: { KinetixAlert },
            template: `
                <div>
                    <KinetixAlert title="Saved" dismissible />
                    <button id="after">Next thing</button>
                </div>
            `,
        });
        const w = mount(Host, {
            global: { plugins: [i18n] },
            attachTo: document.body,
        });

        const close = w.find('button[aria-label="Hide for now"]');
        (close.element as HTMLButtonElement).focus();
        await close.trigger('click');
        await flushPromises();

        expect(document.activeElement?.id).toBe('after');
        w.unmount();
    });

    it('re-opening through v-model forgets a remembered close', async () => {
        const open = ref(true);
        const Host = defineComponent({
            components: { KinetixAlert },
            setup: () => ({ open }),
            template: `<KinetixAlert v-model:open="open" title="Promo" dismissible dismiss-mode="device" dismiss-key="promo" />`,
        });
        const w = mount(Host, {
            global: { plugins: [i18n] },
            attachTo: document.body,
        });

        await w.find('button[aria-label="Dismiss"]').trigger('click');
        await flushPromises();
        expect(open.value).toBe(false);
        expect(localStorage.length).toBe(1);

        open.value = true;
        await flushPromises();

        expect(w.find('[data-slot="alert"]').exists()).toBe(true);
        expect(localStorage.length).toBe(0);
        w.unmount();
    });

    it('swaps instantly when the transition is `none`', async () => {
        const w = mountIt({ dismissible: true, transition: 'none' });

        await button(w, 'Hide for now')?.trigger('click');

        // No leave transition to wait out.
        expect(surface(w).exists()).toBe(false);
        w.unmount();
    });
});
