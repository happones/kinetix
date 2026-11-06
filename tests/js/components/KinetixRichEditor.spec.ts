import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
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

describe('basic editor sanitizes untrusted HTML', () => {
    const doc = document as unknown as {
        execCommand?: (...args: unknown[]) => boolean;
    };

    afterEach(() => {
        delete doc.execCommand;
        vi.unstubAllGlobals();
    });

    const payload =
        '<p onclick="alert(1)">hi<img src=x onerror="alert(1)"></p><script>alert(2)</script><a href="javascript:alert(3)">x</a>';

    const expectClean = (html: string) => {
        expect(html).not.toMatch(/\son\w+=/i);
        expect(html).not.toContain('<img');
        expect(html).not.toContain('<script');
        expect(html).not.toContain('javascript:');
    };

    it('mounts a stored payload with no handlers, images or scripts', () => {
        const w = mountWith(KinetixRichEditorBasic, { value: payload });
        const surface = w.find('[role="textbox"]').element;

        expectClean(surface.innerHTML);
        expect(surface.querySelector('img, script, [onclick]')).toBeNull();
        expect(surface.textContent).toContain('hi');
    });

    it('sanitizes the disabled rendering and external value changes', async () => {
        const w = mountWith(KinetixRichEditorBasic, {
            value: '',
            disabled: true,
        });
        await w.setProps({ value: payload });
        const surface = w.find('[role="textbox"]').element;

        expect(surface.getAttribute('contenteditable')).toBe('false');
        expectClean(surface.innerHTML);
    });

    it('does not rewrite the surface when the value is the sanitized echo', async () => {
        const w = mountWith(KinetixRichEditorBasic, {
            value: '<p><a href="https://a.test/">x</a></p>',
        });
        const surface = w.find('[role="textbox"]').element;
        const link = surface.querySelector('a');

        await w.setProps({ value: surface.innerHTML });

        expect(surface.querySelector('a')).toBe(link);
    });

    const transferOf = (data: Record<string, string>) => ({
        types: Object.keys(data),
        getData: (type: string) => data[type] ?? '',
    });

    it('sanitizes pasted HTML before inserting it', async () => {
        const exec = vi.fn(() => true);
        doc.execCommand = exec;
        const w = mountWith(KinetixRichEditorBasic, { value: '' });
        const event = new Event('paste', { bubbles: true, cancelable: true });
        Object.defineProperty(event, 'clipboardData', {
            value: transferOf({ 'text/html': payload, 'text/plain': 'hi' }),
        });

        w.find('[role="textbox"]').element.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(exec).toHaveBeenCalledWith(
            'insertHTML',
            false,
            expect.any(String),
        );
        const inserted = exec.mock.calls[0][2] as unknown as string;
        expectClean(inserted);
        expect(inserted).toContain('hi');
    });

    it('inserts pasted plain text as text', () => {
        const exec = vi.fn(() => true);
        doc.execCommand = exec;
        const w = mountWith(KinetixRichEditorBasic, { value: '' });
        const event = new Event('paste', { bubbles: true, cancelable: true });
        Object.defineProperty(event, 'clipboardData', {
            value: transferOf({ 'text/plain': '<img src=x onerror=1>' }),
        });

        w.find('[role="textbox"]').element.dispatchEvent(event);

        expect(exec).toHaveBeenCalledWith(
            'insertText',
            false,
            '<img src=x onerror=1>',
        );
    });

    it('sanitizes dropped HTML before inserting it', () => {
        const exec = vi.fn(() => true);
        doc.execCommand = exec;
        const w = mountWith(KinetixRichEditorBasic, { value: '' });
        const event = new Event('drop', { bubbles: true, cancelable: true });
        Object.defineProperty(event, 'dataTransfer', {
            value: transferOf({ 'text/html': payload }),
        });

        w.find('[role="textbox"]').element.dispatchEvent(event);

        expect(event.defaultPrevented).toBe(true);
        expect(exec).toHaveBeenCalledWith(
            'insertHTML',
            false,
            expect.any(String),
        );
        expectClean(exec.mock.calls[0][2] as unknown as string);
    });

    it('lets a drag inside the surface move natively, then sanitizes outside drops again', () => {
        const exec = vi.fn(() => true);
        doc.execCommand = exec;
        const w = mountWith(KinetixRichEditorBasic, {
            value: '<p>one two</p>',
        });
        const surface = w.find('[role="textbox"]').element;
        const dropOf = (html: string) => {
            const event = new Event('drop', {
                bubbles: true,
                cancelable: true,
            });
            Object.defineProperty(event, 'dataTransfer', {
                value: transferOf({ 'text/html': html }),
            });

            return event;
        };

        surface.dispatchEvent(new Event('dragstart', { bubbles: true }));
        const internal = dropOf('<p>two</p>');
        surface.dispatchEvent(internal);

        expect(internal.defaultPrevented).toBe(false);
        expect(exec).not.toHaveBeenCalled();

        // The flag is spent: the next drop, from outside, is sanitized.
        const external = dropOf(payload);
        surface.dispatchEvent(external);

        expect(external.defaultPrevented).toBe(true);
        expect(exec).toHaveBeenCalledTimes(1);
        expectClean(exec.mock.calls[0][2] as unknown as string);

        // A drag that leaves the surface and ends elsewhere clears it too.
        surface.dispatchEvent(new Event('dragstart', { bubbles: true }));
        surface.dispatchEvent(new Event('dragend', { bubbles: true }));
        const later = dropOf(payload);
        surface.dispatchEvent(later);

        expect(later.defaultPrevented).toBe(true);
        expect(exec).toHaveBeenCalledTimes(2);
    });

    it('refuses a javascript: link from the prompt', async () => {
        const exec = vi.fn(() => true);
        doc.execCommand = exec;
        const prompt = vi.fn(() => 'javascript:alert(1)');
        vi.stubGlobal('prompt', prompt);
        const w = mountWith(KinetixRichEditorBasic, { value: '' });

        const buttons = w.findAll('button');
        await buttons[buttons.length - 1].trigger('click');

        expect(exec).not.toHaveBeenCalledWith(
            'createLink',
            expect.anything(),
            expect.anything(),
        );

        prompt.mockReturnValue('https://a.test/');
        await buttons[buttons.length - 1].trigger('click');

        expect(exec).toHaveBeenCalledWith(
            'createLink',
            false,
            'https://a.test/',
        );
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

    it('cannot inject attributes or unsafe schemes through a link', async () => {
        const w = mountWith(KinetixRichEditorMarkdown, {
            value: "[x](http://a\"onmouseover=\"alert(1)) [y](javascript:alert(1)) [z]('/doc'style='x)",
        });
        await w.findAll('button')[1].trigger('click');
        const preview = w.find('.kx-md').element;
        const links = preview.querySelectorAll('a');

        expect(links.length).toBeGreaterThan(0);
        for (const link of Array.from(links)) {
            expect(link.getAttributeNames().sort()).toEqual([
                'href',
                'rel',
                'target',
            ]);
            expect(link.getAttribute('href')).not.toMatch(/^javascript:/i);
        }
        expect(preview.innerHTML).not.toContain('href="javascript:');
        expect(preview.textContent).toContain('y');
    });

    it('emits the raw markdown on input', async () => {
        const w = mountWith(KinetixRichEditorMarkdown, { value: '' });
        const ta = w.find('textarea');
        await ta.setValue('## Heading');
        const events = w.emitted('update:value');
        expect(events?.[events.length - 1][0]).toBe('## Heading');
    });
});
