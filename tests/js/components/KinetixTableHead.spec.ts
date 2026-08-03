import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { createI18n } from 'vue-i18n';
import KinetixTableHead from '@/components/Table/KinetixTableHead.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    fallbackWarn: false,
    messages: {
        en: {
            kinetix: {
                select_all: 'Select all',
                actions: 'Actions',
                reorder: 'Reorder',
            },
        },
    },
});

const columns = [
    { name: 'title', label: 'Title', isSortable: true },
    { name: 'status', label: 'Status', isSortable: false },
];

const mountHead = (overrides: Record<string, unknown> = {}) =>
    mount(KinetixTableHead, {
        props: {
            columnsToRender: columns,
            sort: null,
            direction: null,
            hasBulkActions: false,
            hasRecordActions: false,
            allOnPageSelected: false,
            ...overrides,
        },
        global: { plugins: [i18n] },
    });

describe('KinetixTableHead accessibility', () => {
    it('exposes aria-sort=none on sortable-but-unsorted columns only', () => {
        const wrapper = mountHead();
        const [sortable, plain] = wrapper.findAll('th');

        expect(sortable.attributes('aria-sort')).toBe('none');
        expect(plain.attributes('aria-sort')).toBeUndefined();
    });

    it('flips aria-sort with the active sort direction', async () => {
        const wrapper = mountHead({ sort: 'title', direction: 'asc' });

        expect(wrapper.find('th').attributes('aria-sort')).toBe('ascending');

        await wrapper.setProps({ direction: 'desc' });
        expect(wrapper.find('th').attributes('aria-sort')).toBe('descending');
    });

    it('marks the sort icon decorative', () => {
        const wrapper = mountHead();

        expect(wrapper.find('th button svg').attributes('aria-hidden')).toBe(
            'true',
        );
    });

    it('labels the select-all checkbox', () => {
        const wrapper = mountHead({ hasBulkActions: true });

        expect(wrapper.find('[aria-label="Select all"]').exists()).toBe(true);
    });

    it('names the reorder and actions columns for screen readers', () => {
        const wrapper = mountHead({
            reorderable: true,
            hasRecordActions: true,
        });

        const srOnly = wrapper.findAll('.sr-only').map((node) => node.text());
        expect(srOnly).toContain('Reorder');
        expect(srOnly).toContain('Actions');
    });
});
