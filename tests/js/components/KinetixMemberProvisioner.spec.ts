import { mount } from '@vue/test-utils';
import { defineComponent, h } from 'vue';
import { describe, expect, it } from 'vitest';
import KinetixMemberProvisioner from '@/components/KinetixMemberProvisioner.vue';
import { i18n } from './i18n';

/**
 * The real KinetixSelect is Reka UI (a portalled listbox), awkward to drive in
 * happy-dom. Stub it with a native <select> that mirrors the props/events the
 * provisioner relies on (`value` / `options` / `update:value`).
 */
const KinetixSelectStub = defineComponent({
    props: {
        value: { type: [String, Number, null], default: null },
        options: { type: Object, default: () => ({}) },
    },
    emits: ['update:value'],
    setup(props, { emit }) {
        return () =>
            h(
                'select',
                {
                    value: props.value,
                    onChange: (e: Event) =>
                        emit(
                            'update:value',
                            (e.target as HTMLSelectElement).value,
                        ),
                },
                Object.entries(props.options).map(([val, label]) =>
                    h('option', { value: val }, label as string),
                ),
            );
    },
});

function mountProvisioner(assignableRoles: string[] = ['editor', 'viewer']) {
    return mount(KinetixMemberProvisioner, {
        props: { assignableRoles },
        global: {
            plugins: [i18n],
            stubs: { KinetixSelect: KinetixSelectStub },
        },
    });
}

describe('KinetixMemberProvisioner', () => {
    it('only offers the assignable roles in the dropdown', () => {
        const wrapper = mountProvisioner(['editor', 'viewer']);
        const options = wrapper.findAll('option').map((o) => o.element.value);

        // Crucially, a privileged role like "admin" is never selectable here.
        expect(options).toEqual(['editor', 'viewer']);
    });

    it('headline-cases the role labels while emitting the raw slug', async () => {
        const wrapper = mountProvisioner(['support-agent']);

        // Display label is humanized; the submitted value stays the slug.
        expect(wrapper.find('option').text()).toBe('Support Agent');

        await wrapper.get('input[type="email"]').setValue('a@example.com');
        await wrapper.get('form').trigger('submit');

        expect(wrapper.emitted('submit')?.[0]).toEqual([
            'a@example.com',
            'support-agent',
        ]);
    });

    it('emits submit with the email and selected role', async () => {
        const wrapper = mountProvisioner(['editor', 'viewer']);

        await wrapper.get('input[type="email"]').setValue('new@example.com');
        await wrapper.get('select').setValue('viewer');
        await wrapper.get('form').trigger('submit');

        expect(wrapper.emitted('submit')?.[0]).toEqual([
            'new@example.com',
            'viewer',
        ]);
    });

    it('does not emit when the email is empty', async () => {
        const wrapper = mountProvisioner();

        await wrapper.get('form').trigger('submit');

        expect(wrapper.emitted('submit')).toBeUndefined();
    });

    it('headline-cases role labels via the shared helper', async () => {
        // Both membership selects (provisioner + member list) share this
        // helper, so the same role never renders two different labels on the
        // same screen.
        const { roleLabel } = await import('@/composables/useKinetixMembers');

        expect(roleLabel('support-agent')).toBe('Support Agent');
        expect(roleLabel('billing_admin')).toBe('Billing Admin');

        const wrapper = mountProvisioner(['support-agent']);
        expect(wrapper.text()).toContain('Support Agent');
    });
});
