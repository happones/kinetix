import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';
import KinetixCollapsible from '@/components/KinetixCollapsible.vue';

/**
 * The disclosure's DOM contract. The animation itself is CSS driven by a height
 * Reka measures at runtime, which no DOM-less environment can evaluate — that
 * is asserted in the browser by `npm run test:e2e:collapsible`. This spec pins
 * the structure that animation hangs off, in CI, without a browser.
 */
const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: { en: { kinetix: {} } },
});

const mountWith = (props: Record<string, unknown> = {}) =>
    mount(KinetixCollapsible, {
        props: { title: 'Reading options', ...props },
        slots: { default: '<p class="kx-body">body</p>' },
        global: { plugins: [i18n] },
    });

describe('KinetixCollapsible', () => {
    it('is a real button announcing its collapsed state', () => {
        const w = mountWith();

        const trigger = w.find('button');
        expect(trigger.exists()).toBe(true);
        expect(trigger.attributes('type')).toBe('button');
        expect(trigger.attributes('aria-expanded')).toBe('false');
    });

    it('keeps the summary visible while collapsed, so folded is not unknown', () => {
        const w = mountWith({ summary: 'Comma · with header row' });

        expect(w.text()).toContain('Reading options');
        expect(w.text()).toContain('Comma · with header row');
    });

    it('opens on activation and reports it', async () => {
        const w = mountWith();

        await w.find('button').trigger('click');

        expect(w.find('button').attributes('aria-expanded')).toBe('true');
        expect(w.emitted('update:open')).toEqual([[true]]);
        expect(w.find('.kx-body').exists()).toBe(true);
    });

    it('carries the animated content class and the state its keyframes key off', async () => {
        const w = mountWith();

        // Reka suppresses `data-state` on the FIRST render on purpose, so
        // content that starts open does not animate in. It appears on a real
        // toggle, which is when the animation should run.
        await w.find('button').trigger('click');
        await new Promise((resolve) => requestAnimationFrame(resolve));

        const content = w.find('.kx-collapsible-content');
        expect(content.exists()).toBe(true);
        expect(content.attributes('data-state')).toBe('open');
        // `overflow-hidden` is what makes a height animation clip instead of
        // spilling the content while it runs.
        expect(content.classes()).toContain('overflow-hidden');
    });

    it('honours a controlled open state', () => {
        const w = mountWith({ open: true });

        expect(w.find('button').attributes('aria-expanded')).toBe('true');
    });

    it('drops its card chrome in bare mode', () => {
        const framed = mountWith();
        const bare = mountWith({ bare: true });

        expect(framed.html()).toContain('border-border');
        expect(bare.html()).not.toContain('border-border');
    });
});
