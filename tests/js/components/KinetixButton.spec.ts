import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import KinetixButton from '@/components/KinetixButton.vue';

describe('KinetixButton', () => {
    it('renders a type=button by default with the label slot', () => {
        const wrapper = mount(KinetixButton, {
            slots: { default: 'Export' },
        });

        const button = wrapper.get('button');
        expect(button.attributes('type')).toBe('button');
        expect(button.attributes('disabled')).toBeUndefined();
        expect(button.text()).toBe('Export');
    });

    it('disables itself and swaps the icon slot for a spinner while loading', () => {
        const wrapper = mount(KinetixButton, {
            props: { loading: true },
            slots: {
                default: 'Export',
                icon: '<svg data-test="action-icon" />',
            },
        });

        const button = wrapper.get('button');
        expect(button.attributes('disabled')).toBeDefined();
        expect(button.attributes('aria-busy')).toBe('true');
        expect(wrapper.find('.animate-spin').exists()).toBe(true);
        expect(wrapper.find('[data-test="action-icon"]').exists()).toBe(false);
    });

    it('shows the icon slot and no spinner when idle', () => {
        const wrapper = mount(KinetixButton, {
            slots: {
                default: 'Export',
                icon: '<svg data-test="action-icon" />',
            },
        });

        expect(wrapper.find('.animate-spin').exists()).toBe(false);
        expect(wrapper.find('[data-test="action-icon"]').exists()).toBe(true);
    });

    it('swallows clicks while loading or disabled (double-click guard)', async () => {
        const onClick = vi.fn();
        const wrapper = mount(KinetixButton, {
            props: { loading: true },
            attrs: { onClick },
            slots: { default: 'Export' },
        });

        await wrapper.get('button').trigger('click');
        expect(onClick).not.toHaveBeenCalled();

        await wrapper.setProps({ loading: false, disabled: true });
        await wrapper.get('button').trigger('click');
        expect(onClick).not.toHaveBeenCalled();

        await wrapper.setProps({ disabled: false });
        await wrapper.get('button').trigger('click');
        expect(onClick).toHaveBeenCalledTimes(1);
    });
});
