import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('vue-sonner', () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

import KinetixRichEditor from '@/components/KinetixRichEditor.vue';
import KinetixRichEditorBasic from '@/components/KinetixRichEditorBasic.vue';
import KinetixRichEditorMarkdown from '@/components/KinetixRichEditorMarkdown.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: {} } },
});

const mountWith = (c: unknown, props: Record<string, unknown>) =>
    mount(c as never, { props, global: { plugins: [i18n] } });

describe('KinetixRichEditor driver selection', () => {
    it('renders the basic editor by default', () => {
        const w = mountWith(KinetixRichEditor, { value: '<p>hi</p>' });
        expect(w.findComponent(KinetixRichEditorBasic).exists()).toBe(true);
    });

    it('renders the markdown editor when selected', () => {
        const w = mountWith(KinetixRichEditor, {
            editor: 'markdown',
            value: '# Hi',
        });
        expect(w.findComponent(KinetixRichEditorMarkdown).exists()).toBe(true);
    });
});

describe('basic editor', () => {
    it('emits HTML from the contenteditable surface', async () => {
        const w = mountWith(KinetixRichEditorBasic, { value: '' });
        const surface = w.find('[contenteditable="true"]');
        surface.element.innerHTML = '<p>hello</p>';
        await surface.trigger('input');
        const events = w.emitted('update:value');
        expect(events?.[events.length - 1][0]).toBe('<p>hello</p>');
    });
});

describe('markdown editor', () => {
    it('renders a safe HTML preview and escapes raw HTML', async () => {
        const w = mountWith(KinetixRichEditorMarkdown, {
            value: '# Title\n\n**bold** and <script>x</script>',
        });
        // Switch to the preview tab (second toolbar button).
        await w.findAll('button')[1].trigger('click');
        const html = w.find('.kx-md').html();
        expect(html).toContain('<h1>Title</h1>');
        expect(html).toContain('<strong>bold</strong>');
        // Raw HTML is escaped, not injected.
        expect(html).not.toContain('<script>x</script>');
        expect(html).toContain('&lt;script&gt;');
    });

    it('emits the raw markdown on input', async () => {
        const w = mountWith(KinetixRichEditorMarkdown, { value: '' });
        const ta = w.find('textarea');
        await ta.setValue('## Heading');
        const events = w.emitted('update:value');
        expect(events?.[events.length - 1][0]).toBe('## Heading');
    });
});
