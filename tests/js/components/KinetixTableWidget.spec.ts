import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';
import KinetixTableWidget from '@/components/KinetixTableWidget.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'es',
    missingWarn: false,
    messages: { es: { kinetix: { no_records_found: 'No hay registros.' } } },
});

describe('KinetixTableWidget', () => {
    it('says it is empty in the app locale', () => {
        const w = mount(KinetixTableWidget, {
            props: {
                widget: {
                    id: 't1',
                    type: 'table',
                    columnSpan: 12,
                    sort: 0,
                    data: { headers: ['A'], rows: [] },
                } as any,
            },
            global: { plugins: [i18n] },
        });

        expect(w.text()).toContain('No hay registros.');
    });
});
