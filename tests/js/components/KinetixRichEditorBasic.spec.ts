import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

/** A minimal Tiptap engine: the toolbar only needs chain/isActive. */
const tiptap = vi.hoisted(() => {
    const active = new Set<string>();
    const ran: string[] = [];

    class Editor {
        commands = { setContent: () => {} };
        constructor(private readonly options: Record<string, any>) {}
        isActive(name: string, attrs?: { level: number }): boolean {
            return active.has(attrs ? `${name}${attrs.level}` : name);
        }
        getHTML(): string {
            return '';
        }
        chain(): Record<string, (...args: any[]) => any> {
            const chain: Record<string, (...args: any[]) => any> = new Proxy(
                {},
                {
                    get: (_target, key: string) =>
                        key === 'run'
                            ? () => {
                                  this.options.onTransaction?.();
                              }
                            : (...args: any[]) => {
                                  if (key !== 'focus') {
                                      ran.push(key);

                                      if (key === 'toggleBold') {
                                          active.add('bold');
                                      }
                                  }

                                  void args;

                                  return chain;
                              },
                },
            );

            return chain;
        }
        destroy(): void {}
    }

    return { active, ran, Editor };
});

vi.mock('@/composables/useKinetixRichEditorEngine', () => ({
    loadKinetixTiptap: () =>
        Promise.resolve({ Editor: tiptap.Editor, StarterKit: {} }),
}));

import KinetixRichEditorBasic from '@/components/KinetixRichEditorBasic.vue';
import KinetixRichEditorTiptap from '@/components/KinetixRichEditorTiptap.vue';

const labels: Record<string, string> = {
    editor_toolbar: 'Formatting',
    editor_bold: 'Bold',
    editor_italic: 'Italic',
    editor_underline: 'Underline',
    editor_strike: 'Strikethrough',
    editor_heading_1: 'Heading 1',
    editor_heading_2: 'Heading 2',
    editor_bullet_list: 'Bulleted list',
    editor_ordered_list: 'Numbered list',
    editor_blockquote: 'Quote',
    editor_link: 'Link',
    editor_link_prompt: 'Link address',
};

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: { en: { kinetix: labels } },
});

const doc = document as unknown as {
    execCommand?: (...args: unknown[]) => boolean;
    queryCommandState?: (command: string) => boolean;
    queryCommandValue?: (command: string) => string;
};

const mountBasic = (props: Record<string, unknown> = {}) =>
    mount(KinetixRichEditorBasic, {
        props: { value: '', ...props },
        attachTo: document.body,
        global: { plugins: [i18n] },
    });

const toolbarButtons = (w: { findAll: (s: string) => any[] }) =>
    w.findAll('[role="toolbar"] button');

afterEach(() => {
    delete doc.execCommand;
    delete doc.queryCommandState;
    delete doc.queryCommandValue;
    vi.unstubAllGlobals();
    document.body.innerHTML = '';
});

describe('KinetixRichEditorBasic toolbar', () => {
    beforeEach(() => {
        doc.execCommand = vi.fn(() => true);
    });

    it('is a labelled toolbar of named buttons', () => {
        const w = mountBasic();
        const toolbar = w.get('[role="toolbar"]');

        expect(toolbar.attributes('aria-label')).toBe('Formatting');
        expect(toolbar.attributes('aria-orientation')).toBe('horizontal');

        const buttons = toolbarButtons(w);
        expect(buttons).toHaveLength(9);

        // No bare icon-only button: every one has a translated name + tooltip.
        const names = buttons.map((b) => b.attributes('aria-label'));
        expect(names).toEqual([
            'Bold',
            'Italic',
            'Underline',
            'Strikethrough',
            'Heading 1',
            'Heading 2',
            'Bulleted list',
            'Numbered list',
            'Link',
        ]);
        buttons.forEach((b) => {
            expect(b.attributes('title')).toBe(b.attributes('aria-label'));
            expect(b.attributes('type')).toBe('button');
            expect(b.find('svg').attributes('aria-hidden')).toBe('true');
        });
    });

    it('marks the toggles with aria-pressed, but not the link', () => {
        const w = mountBasic();
        const [bold, ...rest] = toolbarButtons(w);
        const link = rest[rest.length - 1];

        expect(bold.attributes('aria-pressed')).toBe('false');
        expect(link.attributes('aria-pressed')).toBeUndefined();
    });

    it('has one tab stop and moves focus with the arrow keys, Home and End', async () => {
        const w = mountBasic();
        const buttons = toolbarButtons(w);

        // Roving tabindex: the buttons are out of the tab order; the toolbar
        // is the one tab stop, then the current button is.
        buttons.forEach((b) => expect(b.attributes('tabindex')).toBe('-1'));

        (buttons[0].element as HTMLElement).focus();
        await buttons[0].trigger('focus');
        await buttons[0].trigger('keydown', { key: 'ArrowRight' });
        expect(document.activeElement).toBe(buttons[1].element);
        expect(
            toolbarButtons(w).filter((b) => b.attributes('tabindex') === '0'),
        ).toHaveLength(1);

        await buttons[1].trigger('keydown', { key: 'ArrowLeft' });
        expect(document.activeElement).toBe(buttons[0].element);

        await buttons[0].trigger('keydown', { key: 'End' });
        expect(document.activeElement).toBe(buttons[8].element);

        await buttons[8].trigger('keydown', { key: 'Home' });
        expect(document.activeElement).toBe(buttons[0].element);

        // `loop`: ArrowLeft on the first wraps to the last.
        await buttons[0].trigger('keydown', { key: 'ArrowLeft' });
        expect(document.activeElement).toBe(buttons[8].element);
    });

    it('runs a command on click (Enter/Space activate a button) and keeps the caret on mousedown', async () => {
        const w = mountBasic();
        const bold = toolbarButtons(w)[0];

        const down = new MouseEvent('mousedown', {
            bubbles: true,
            cancelable: true,
        });
        bold.element.dispatchEvent(down);
        // mousedown never takes focus from the editing surface…
        expect(down.defaultPrevented).toBe(true);
        expect(doc.execCommand).not.toHaveBeenCalled();

        // …the click (what Enter and Space fire on a native button) runs it.
        await bold.trigger('click');
        expect(doc.execCommand).toHaveBeenCalledWith('bold', false, undefined);
    });

    it('flips aria-pressed after formatting from the selection state', async () => {
        const state = new Set<string>();
        doc.queryCommandState = vi.fn((command: string) => state.has(command));
        doc.queryCommandValue = vi.fn(() => '');
        doc.execCommand = vi.fn((command: unknown) => {
            state.add(String(command));

            return true;
        });

        const w = mountBasic();
        const bold = toolbarButtons(w)[0];

        await bold.trigger('click');

        expect(bold.attributes('aria-pressed')).toBe('true');
        expect(toolbarButtons(w)[1].attributes('aria-pressed')).toBe('false');
    });

    it('reports the heading level from formatBlock', async () => {
        doc.queryCommandState = vi.fn(() => false);
        doc.queryCommandValue = vi.fn(() => 'h2');

        const w = mountBasic();
        await toolbarButtons(w)[5].trigger('click');

        expect(toolbarButtons(w)[5].attributes('aria-pressed')).toBe('true');
        expect(toolbarButtons(w)[4].attributes('aria-pressed')).toBe('false');
    });

    it('survives a browser where queryCommandState throws', async () => {
        doc.queryCommandState = vi.fn(() => {
            throw new Error('not an editing host');
        });
        doc.queryCommandValue = vi.fn(() => {
            throw new Error('not an editing host');
        });

        const w = mountBasic();
        await toolbarButtons(w)[0].trigger('click');

        expect(toolbarButtons(w)[0].attributes('aria-pressed')).toBe('false');
    });

    it('asks for the link address in the user language', async () => {
        const prompt = vi.fn(() => 'https://a.test/');
        vi.stubGlobal('prompt', prompt);
        const w = mountBasic();

        await toolbarButtons(w)[8].trigger('click');

        expect(prompt).toHaveBeenCalledWith('Link address');
        expect(doc.execCommand).toHaveBeenCalledWith(
            'createLink',
            false,
            'https://a.test/',
        );
    });

    it('stops tracking the selection once the surface loses focus', async () => {
        const add = vi.spyOn(document, 'addEventListener');
        const remove = vi.spyOn(document, 'removeEventListener');
        const w = mountBasic();
        const surface = w.get('[role="textbox"]');

        await surface.trigger('focus');
        expect(add).toHaveBeenCalledWith(
            'selectionchange',
            expect.any(Function),
        );

        await surface.trigger('blur');
        expect(remove).toHaveBeenCalledWith(
            'selectionchange',
            expect.any(Function),
        );
    });

    it('disables every tool with the field', () => {
        const w = mountBasic({ disabled: true });

        toolbarButtons(w).forEach((b) => {
            expect(b.attributes('disabled')).toBeDefined();
        });
    });
});

describe('KinetixRichEditorTiptap toolbar', () => {
    beforeEach(() => {
        tiptap.active.clear();
        tiptap.ran.length = 0;
    });

    it('is the same labelled toolbar, with aria-pressed from the editor state', async () => {
        const w = mount(KinetixRichEditorTiptap, {
            props: { value: '' },
            attachTo: document.body,
            global: { plugins: [i18n] },
        });
        await flushPromises();

        expect(w.get('[role="toolbar"]').attributes('aria-label')).toBe(
            'Formatting',
        );

        const buttons = toolbarButtons(w);
        expect(buttons.map((b) => b.attributes('aria-label'))).toEqual([
            'Bold',
            'Italic',
            'Strikethrough',
            'Heading 1',
            'Heading 2',
            'Bulleted list',
            'Numbered list',
            'Quote',
        ]);
        buttons.forEach((b) => {
            expect(b.attributes('aria-pressed')).toBe('false');
            expect(b.attributes('title')).toBe(b.attributes('aria-label'));
        });

        await buttons[0].trigger('click');

        expect(tiptap.ran).toEqual(['toggleBold']);
        expect(toolbarButtons(w)[0].attributes('aria-pressed')).toBe('true');

        // Arrow keys rove between the buttons here too.
        (buttons[0].element as HTMLElement).focus();
        await buttons[0].trigger('keydown', { key: 'ArrowRight' });
        expect(document.activeElement).toBe(buttons[1].element);
    });
});
