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
