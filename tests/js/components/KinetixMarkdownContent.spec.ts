import { enableAutoUnmount, flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { i18n } from './i18n';
import KinetixMarkdownContent from '@/components/KinetixMarkdownContent.vue';

// The shape MarkdownRenderer emits (see MarkdownRendererTest).
const copy =
    '<button type="button" class="kx-code-copy" data-kx-copy aria-label="Copy code"><svg></svg></button>';
const single = `<div class="kx-code" data-lang="php"><div class="kx-code-bar"><span class="kx-code-label">php</span>${copy}</div><pre class="kx-code-plain"><code>echo 1;</code></pre></div>`;
const group =
    `<div class="kx-code-group" id="kx-code-ab-1"><div class="kx-code-bar"><div class="kx-code-tabs" role="tablist" aria-label="Code examples">` +
    ['npm', 'yarn', 'pnpm']
        .map(
            (name, i) =>
                `<button type="button" role="tab" class="kx-code-tab" id="kx-code-ab-1-${i}" aria-controls="kx-code-ab-1-${i}-panel" aria-selected="${i === 0}" tabindex="${i === 0 ? 0 : -1}">${name}</button>`,
        )
        .join('') +
    `</div>${copy}</div>` +
    ['npm i', 'yarn add', 'pnpm add']
        .map(
            (code, i) =>
                `<div class="kx-code" role="tabpanel" id="kx-code-ab-1-${i}-panel" aria-labelledby="kx-code-ab-1-${i}"${i === 0 ? '' : ' hidden'}><pre><code>${code}</code></pre></div>`,
        )
        .join('') +
    '</div>';

const writeText = vi.fn();

enableAutoUnmount(afterEach);

beforeEach(() => {
    writeText.mockReset().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', {
        value: { writeText },
        configurable: true,
    });
});

const mountContent = (html: string) =>
    mount(KinetixMarkdownContent, {
        props: { html, as: 'article' },
        attrs: { lang: 'es' },
        global: { plugins: [i18n] },
        attachTo: document.body,
    });

const tabs = (w: ReturnType<typeof mountContent>) => w.findAll('[role="tab"]');
const visible = (w: ReturnType<typeof mountContent>) =>
    w
        .findAll('[role="tabpanel"]')
        .filter((panel) => panel.attributes('hidden') === undefined)
        .map((panel) => panel.text());

describe('KinetixMarkdownContent', () => {
    it('renders the html on the element it is asked for, with fallthrough attributes', () => {
        const w = mountContent('<p>Hola</p>');

        expect(w.element.tagName).toBe('ARTICLE');
        expect(w.attributes('lang')).toBe('es');
        expect(w.html()).toContain('<p>Hola</p>');
    });

    it('copies a code block and marks the button for a moment', async () => {
        vi.useFakeTimers();
        const w = mountContent(single);
        const button = w.get('[data-kx-copy]');

        await button.trigger('click');
        await flushPromises();

        expect(writeText).toHaveBeenCalledWith('echo 1;');
        expect(button.attributes('data-copied')).toBe('true');

        vi.advanceTimersByTime(2000);
        expect(button.attributes('data-copied')).toBeUndefined();
        vi.useRealTimers();
    });

    it('switches a code group by click and copies the visible panel', async () => {
        const w = mountContent(group);

        await tabs(w)[2].trigger('click');

        expect(visible(w)).toEqual(['pnpm add']);
        expect(tabs(w).map((tab) => tab.attributes('aria-selected'))).toEqual([
            'false',
            'false',
            'true',
        ]);
        expect(tabs(w).map((tab) => tab.attributes('tabindex'))).toEqual([
            '-1',
            '-1',
            '0',
        ]);

        await w.get('[data-kx-copy]').trigger('click');
        await flushPromises();

        expect(writeText).toHaveBeenCalledWith('pnpm add');
    });

    it('moves between tabs with the arrow keys, Home and End, wrapping around', async () => {
        const w = mountContent(group);

        await tabs(w)[0].trigger('keydown', { key: 'ArrowLeft' });
        expect(visible(w)).toEqual(['pnpm add']);
        expect(document.activeElement).toBe(tabs(w)[2].element);

        await tabs(w)[2].trigger('keydown', { key: 'ArrowRight' });
        expect(visible(w)).toEqual(['npm i']);

        await tabs(w)[0].trigger('keydown', { key: 'End' });
        expect(visible(w)).toEqual(['pnpm add']);

        await tabs(w)[2].trigger('keydown', { key: 'Home' });
        expect(visible(w)).toEqual(['npm i']);
    });

    it('keeps working after the html changes', async () => {
        const w = mountContent(single);

        await w.setProps({ html: group });
        await tabs(w)[1].trigger('click');

        expect(visible(w)).toEqual(['yarn add']);
    });

    it('exposes its root element', () => {
        const w = mountContent(single);

        expect((w.vm as unknown as { el: HTMLElement }).el).toBe(w.element);
    });
});
