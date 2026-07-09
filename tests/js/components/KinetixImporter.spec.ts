import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: {} }) }));

import KinetixImporter from '@/components/KinetixImporter.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: {
        en: {
            kinetix: {
                upload: 'Upload',
                download_template: 'Download template',
            },
        },
    },
});

const mountWith = (props: Record<string, any>) =>
    mount(KinetixImporter, {
        props: { importer: 'token-123', ...props },
        global: { plugins: [i18n] },
    });

describe('KinetixImporter', () => {
    it('shows a download-template link pointing at the template endpoint', () => {
        const w = mountWith({ template: 'ProductImporter.csv' });

        const link = w.find('a[download]');
        expect(link.exists()).toBe(true);
        expect(link.text()).toContain('Download template');
        expect(link.attributes('href')).toBe(
            '/_kinetix/imports/template?importer=token-123',
        );
        expect(link.attributes('download')).toBe('ProductImporter.csv');
    });

    it('hides the link when the importer has no template', () => {
        const w = mountWith({ template: null });

        expect(w.find('a[download]').exists()).toBe(false);
    });
});
