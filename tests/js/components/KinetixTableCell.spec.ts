import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { i18n } from './i18n';
import KinetixTableCell from '@/components/Table/KinetixTableCell.vue';
import CheckboxInputCell from '@/components/Table/cells/CheckboxInputCell.vue';
import ColorCell from '@/components/Table/cells/ColorCell.vue';
import IconCell from '@/components/Table/cells/IconCell.vue';
import ImageCell from '@/components/Table/cells/ImageCell.vue';
import NumberInputCell from '@/components/Table/cells/NumberInputCell.vue';
import ProgressCell from '@/components/Table/cells/ProgressCell.vue';
import SelectInputCell from '@/components/Table/cells/SelectInputCell.vue';
import TextBadgeCell from '@/components/Table/cells/TextBadgeCell.vue';
import TextCell from '@/components/Table/cells/TextCell.vue';
import TextInputCell from '@/components/Table/cells/TextInputCell.vue';
import ToggleInputCell from '@/components/Table/cells/ToggleInputCell.vue';
import ViewCell from '@/components/Table/cells/ViewCell.vue';

describe('KinetixTableCell progress mode', () => {
    it('renders the progress value and bar filled correctly', () => {
        const wrapper = mount(KinetixTableCell, {
            props: {
                col: {
                    name: 'stock',
                    label: 'Stock',
                    type: 'progress',
                },
                record: {
                    id: 1,
                    values: {
                        stock: '45 items',
                    },
                    progress: {
                        stock: 45.0,
                    },
                    progressColors: {
                        stock: 'success',
                    },
                    descriptions: {},
                    icons: {},
                    iconColors: {},
                    badgeColors: {},
                },
                rowIndex: 0,
            },
            global: {
                plugins: [i18n],
            },
        });

        // Verifies value is displayed
        expect(wrapper.text()).toContain('45 items');
        // Verifies the status text class is applied
        expect(wrapper.find('span.text-sm').classes()).toContain(
            'text-success',
        );
        // Verifies progress bar width% is set
        const barFill = wrapper.find('.bg-muted div');
        expect(barFill.exists()).toBe(true);
        expect(barFill.attributes('style')).toContain('width: 45%;');
        expect(barFill.classes()).toContain('bg-success');
    });

    it('renders with default colors if not provided', () => {
        const wrapper = mount(KinetixTableCell, {
            props: {
                col: {
                    name: 'stock',
                    label: 'Stock',
                    type: 'progress',
                },
                record: {
                    id: 1,
                    values: {
                        stock: '80',
                    },
                    progress: {
                        stock: 80.0,
                    },
                    progressColors: {},
                    descriptions: {},
                    icons: {},
                    iconColors: {},
                    badgeColors: {},
                },
                rowIndex: 0,
            },
            global: {
                plugins: [i18n],
            },
        });

        expect(wrapper.text()).toContain('80');
        expect(wrapper.find('span.text-sm').classes()).toContain(
            'text-primary',
        );
        const barFill = wrapper.find('.bg-muted div');
        expect(barFill.attributes('style')).toContain('width: 80%;');
        expect(barFill.classes()).toContain('bg-primary');
    });
});

describe('KinetixTableCell view mode', () => {
    it('renders the custom component with props correctly', () => {
        const MockComponent = {
            template:
                '<div>Mock Component {{ value }} with role {{ role }}</div>',
            props: ['record', 'value', 'role'],
        };

        const wrapper = mount(KinetixTableCell, {
            props: {
                col: {
                    name: 'avatar',
                    label: 'Avatar',
                    type: 'view',
                    view: 'MyUserStatus',
                },
                record: {
                    id: 123,
                    values: {
                        avatar: 'john-doe-url',
                    },
                    progress: {},
                    progressColors: {},
                    viewProps: {
                        avatar: {
                            role: 'admin',
                        },
                    },
                    descriptions: {},
                    icons: {},
                    iconColors: {},
                    badgeColors: {},
                },
                rowIndex: 0,
            },
            global: {
                plugins: [i18n],
                components: {
                    MyUserStatus: MockComponent,
                },
            },
        });

        expect(wrapper.text()).toContain(
            'Mock Component john-doe-url with role admin',
        );
    });
});

const blankRecord = {
    id: 7,
    values: {},
    descriptions: {},
    icons: {},
    iconColors: {},
    badgeColors: {},
    progress: {},
    progressColors: {},
    viewProps: {},
};

const mountCell = (col: Record<string, unknown>, values = {}, over = {}) =>
    mount(KinetixTableCell, {
        props: {
            col: { name: 'field', label: 'Field', ...col },
            record: { ...blankRecord, values, ...over },
            rowIndex: 0,
        },
        global: { plugins: [i18n] },
    });

/** No element rendered at all — the map resolved to nothing. */
const expectBlank = (w: ReturnType<typeof mountCell>): void => {
    expect(w.findAll('*')).toHaveLength(0);
};

describe('KinetixTableCell component map', () => {
    it.each([
        ['text', { type: 'text' }, TextCell],
        ['text (badge)', { type: 'text', isBadge: true }, TextBadgeCell],
        ['progress', { type: 'progress' }, ProgressCell],
        [
            'select-input',
            { type: 'select-input', options: { a: 'A' } },
            SelectInputCell,
        ],
        ['toggle-input', { type: 'toggle-input' }, ToggleInputCell],
        ['text-input', { type: 'text-input' }, TextInputCell],
        ['number-input', { type: 'number-input' }, NumberInputCell],
        ['checkbox-input', { type: 'checkbox-input' }, CheckboxInputCell],
    ])('resolves %s to its cell component', (_label, col, expected) => {
        const w = mountCell(col, { field: 'x' });
        expect(w.findComponent(expected).exists()).toBe(true);
    });

    it('resolves icon to IconCell only when the record carries an icon', () => {
        const withIcon = mountCell(
            { type: 'icon' },
            {},
            { icons: { field: 'edit' } },
        );
        expect(withIcon.findComponent(IconCell).exists()).toBe(true);

        const without = mountCell({ type: 'icon' });
        expect(without.findComponent(IconCell).exists()).toBe(false);
        expectBlank(without);
    });

    it('resolves image to ImageCell only when the record carries a url', () => {
        const withUrl = mountCell({ type: 'image' }, { field: '/a.png' });
        expect(withUrl.findComponent(ImageCell).exists()).toBe(true);
        expect(withUrl.find('img').attributes('src')).toBe('/a.png');

        expectBlank(mountCell({ type: 'image' }));
    });

    it('resolves color to ColorCell only when the record carries a value', () => {
        const withColor = mountCell({ type: 'color' }, { field: '#ff0000' });
        expect(withColor.findComponent(ColorCell).exists()).toBe(true);

        expectBlank(mountCell({ type: 'color' }));
    });

    it('resolves view to ViewCell only when the column names a component', () => {
        const w = mount(KinetixTableCell, {
            props: {
                col: {
                    name: 'field',
                    label: 'Field',
                    type: 'view',
                    view: 'MyCell',
                },
                record: { ...blankRecord, values: { field: 'v' } },
                rowIndex: 0,
            },
            global: {
                plugins: [i18n],
                components: {
                    MyCell: {
                        template: '<b>{{ value }}</b>',
                        props: ['value'],
                    },
                },
            },
        });
        expect(w.findComponent(ViewCell).exists()).toBe(true);
        expect(w.text()).toContain('v');

        expectBlank(mountCell({ type: 'view' }));
    });

    it('renders nothing for an unmapped column type', () => {
        expectBlank(mountCell({ type: 'not-a-real-type' }));
    });

    it('re-emits update-cell from an editable cell with the record id', async () => {
        const w = mountCell({ type: 'text-input' }, { field: 'old' });
        const input = w.find('input');
        (input.element as HTMLInputElement).value = 'new';
        await input.trigger('change');

        expect(w.emitted('update-cell')?.[0]).toEqual([7, 'field', 'new']);
    });

    it('re-emits update-cell from the toggle cell', async () => {
        const w = mountCell({ type: 'toggle-input' }, { field: false });
        await w.find('button').trigger('click');

        expect(w.emitted('update-cell')?.[0]).toEqual([7, 'field', true]);
    });

    it('re-emits copy-to-clipboard from a copyable text cell', async () => {
        const w = mountCell(
            { type: 'text', isCopyable: true },
            { field: 'abc' },
        );
        await w.find('button').trigger('click');

        expect(w.emitted('copy-to-clipboard')?.[0]).toEqual(['abc']);
    });

    it('renders a text cell description above or below the value', () => {
        const above = mountCell(
            { type: 'text' },
            { field: 'v' },
            { descriptions: { field: { text: 'hint', position: 'above' } } },
        );
        expect(above.text()).toContain('hint');

        const below = mountCell(
            { type: 'text' },
            { field: 'v' },
            { descriptions: { field: { text: 'hint', position: 'below' } } },
        );
        expect(below.findAll('span').at(-1)!.text()).toBe('hint');
    });
});
