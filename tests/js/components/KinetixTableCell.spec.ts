import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { i18n } from './i18n';
import KinetixTableCell from '@/components/Table/KinetixTableCell.vue';

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
        expect(wrapper.find('span.text-sm').classes()).toContain('text-success');
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
        expect(wrapper.find('span.text-sm').classes()).toContain('text-primary');
        const barFill = wrapper.find('.bg-muted div');
        expect(barFill.attributes('style')).toContain('width: 80%;');
        expect(barFill.classes()).toContain('bg-primary');
    });
});

describe('KinetixTableCell view mode', () => {
    it('renders the custom component with props correctly', () => {
        const MockComponent = {
            template: '<div>Mock Component {{ value }} with role {{ role }}</div>',
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

        expect(wrapper.text()).toContain('Mock Component john-doe-url with role admin');
    });
});
