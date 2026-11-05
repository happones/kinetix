import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: { kinetix_config: { route_prefix: '_kinetix' } },
    }),
}));

const fetchMock = vi.fn();
vi.mock('@/composables/useKinetixHttp', () => ({
    kinetixFetch: (...a: unknown[]) => fetchMock(...a),
    kinetixRoutePrefix: () => '_kinetix',
}));

import KinetixMailTemplates from '@/components/KinetixMailTemplates.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: { mail_new: 'New template', save: 'Save' } } },
});

const template = {
    id: 1,
    key: 'welcome',
    name: 'Welcome',
    subject: 'Hi {{ name }}',
    body: 'Hello {{ name }}',
    format: 'markdown',
    variables: [{ key: 'name', label: 'Name', sample: 'Ada' }],
    enabled: true,
};

beforeEach(() => {
    fetchMock.mockReset();
    // index load + preview calls resolve to sane defaults
    fetchMock.mockImplementation((url: string) => {
        if (url.endsWith('/mail-templates')) {
            return Promise.resolve({ templates: [template] });
        }
        if (url.endsWith('/preview')) {
            return Promise.resolve({
                subject: 'Hi Ada',
                html: '<p>Hello Ada</p>',
            });
        }

        return Promise.resolve({});
    });
});

describe('KinetixMailTemplates', () => {
    it('lists templates and previews the selected one', async () => {
        const w = mount(KinetixMailTemplates, { global: { plugins: [i18n] } });
        await flushPromises();

        expect(w.text()).toContain('Welcome');
        expect(w.text()).toContain('welcome');

        // Select it → editor fills + preview renders
        await w
            .findAll('button')
            .find((b) => b.text().includes('Welcome'))!
            .trigger('click');
        await new Promise((r) => setTimeout(r, 450));
        await flushPromises();

        const previewCall = fetchMock.mock.calls.find((c) =>
            String(c[0]).endsWith('/preview'),
        );
        expect(previewCall).toBeTruthy();
        expect(w.html()).toContain('Hello Ada');
    });

    it('drops a pending preview when unmounted mid-debounce', async () => {
        vi.useFakeTimers();
        const w = mount(KinetixMailTemplates, { global: { plugins: [i18n] } });

        // The immediate watch armed a preview debounce; unmount before it fires.
        w.unmount();
        fetchMock.mockClear();
        vi.runAllTimers();
        await flushPromises();

        expect(
            fetchMock.mock.calls.filter((c) =>
                String(c[0]).endsWith('/preview'),
            ),
        ).toHaveLength(0);
        vi.useRealTimers();
    });

    it('saves a new template via POST', async () => {
        const w = mount(KinetixMailTemplates, { global: { plugins: [i18n] } });
        await flushPromises();

        // Start a blank draft so saving creates (POST) rather than updates.
        await w
            .findAll('button')
            .find((b) => b.text().includes('New template'))!
            .trigger('click');

        const input = w.find('input');
        await input.setValue('Receipt');
        await w
            .findAll('button')
            .find((b) => b.text().trim() === 'Save')!
            .trigger('click');
        await flushPromises();

        const saveCall = fetchMock.mock.calls.find(
            (c) =>
                String(c[0]).endsWith('/mail-templates') &&
                c[1]?.method === 'POST',
        );
        expect(saveCall).toBeTruthy();
    });
});

describe('KinetixMailTemplates preview isolation', () => {
    const hostile =
        '<p>Hi</p><img src="x" onerror="window.__kinetixMailXss = 1"><script>window.__kinetixMailXss = 2</script>';

    async function mountWithPreview(html: string) {
        fetchMock.mockImplementation((url: string) => {
            if (url.endsWith('/mail-templates')) {
                return Promise.resolve({ templates: [template] });
            }
            if (url.endsWith('/preview')) {
                return Promise.resolve({ subject: 'Hi Ada', html });
            }

            return Promise.resolve({});
        });

        const w = mount(KinetixMailTemplates, {
            attachTo: document.body,
            global: { plugins: [i18n] },
        });
        await flushPromises();
        await new Promise((r) => setTimeout(r, 450));
        await flushPromises();

        return w;
    }

    it('never parses the rendered body into the host document', async () => {
        delete (window as unknown as Record<string, unknown>).__kinetixMailXss;
        const w = await mountWithPreview(hostile);

        // The hostile markup only lives inside the iframe's srcdoc attribute.
        expect(w.find('img').exists()).toBe(false);
        expect(w.find('script').exists()).toBe(false);
        expect(document.querySelector('[onerror]')).toBeNull();
        expect(
            (window as unknown as Record<string, unknown>).__kinetixMailXss,
        ).toBeUndefined();

        const frame = w.find('iframe');
        expect(frame.exists()).toBe(true);
        expect(frame.attributes('srcdoc')).toContain(hostile);
        w.unmount();
    });

    it('sandboxes the preview frame without scripts', async () => {
        const w = await mountWithPreview('<p>Hello Ada</p>');
        const frame = w.find('iframe');

        expect(frame.attributes('sandbox')).toBe('allow-same-origin');
        expect(frame.attributes('sandbox')).not.toContain('allow-scripts');
        expect(frame.attributes('referrerpolicy')).toBe('no-referrer');
        expect(frame.attributes('title')).toBe('kinetix.mail_preview');

        const srcdoc = frame.attributes('srcdoc') ?? '';
        expect(srcdoc.startsWith('<!doctype html>')).toBe(true);
        expect(srcdoc).toContain('<meta charset="utf-8">');
        expect(srcdoc).toContain('Content-Security-Policy');
        expect(srcdoc).toContain('<p>Hello Ada</p>');
        w.unmount();
    });

    it('grows the frame to the rendered height on load', async () => {
        const w = await mountWithPreview('<p>Hello Ada</p>');
        const frame = w.find('iframe');

        Object.defineProperty(frame.element, 'contentDocument', {
            configurable: true,
            value: { documentElement: { scrollHeight: 420 } },
        });
        await frame.trigger('load');

        expect(frame.attributes('style')).toContain('height: 420px');
        w.unmount();
    });
});
