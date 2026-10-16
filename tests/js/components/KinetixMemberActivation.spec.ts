import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import KinetixMemberActivation from '@/components/KinetixMemberActivation.vue';
import { i18n } from './i18n';

vi.mock('@inertiajs/vue3', () => ({
    useForm: (data: Record<string, unknown>) =>
        reactive({ ...data, errors: {}, processing: false, post: vi.fn() }),
}));

const mountWith = (props: Record<string, unknown>) =>
    mount(KinetixMemberActivation, {
        props: { action: '/members/activate/1', ...props },
        global: { plugins: [i18n] },
    });

describe('KinetixMemberActivation', () => {
    it('says which business is inviting the person', () => {
        const wrapper = mountWith({
            identifier: 'jane@example.com',
            team: 'Acme Coffee',
        });

        expect(wrapper.text()).toContain(
            'You were invited to join Acme Coffee.',
        );
        expect(wrapper.text()).toContain('jane@example.com');
    });

    it('leaves the line out when there is no team', () => {
        const wrapper = mountWith({ identifier: 'jane@example.com' });

        expect(wrapper.text()).not.toContain('You were invited');
    });
});
