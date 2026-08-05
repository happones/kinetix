import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import KinetixRoleEditorModal from '@/components/Roles/KinetixRoleEditorModal.vue';
import type { KinetixRole } from '@/types/kinetix';
import { i18n } from './i18n';

async function mountModal(
    options: {
        role?: KinetixRole | null;
        canCreateGlobal?: boolean;
    } = {},
) {
    // Open AFTER mount so the draft-seeding `watch(open)` fires and reka's
    // portal renders before the assertions query the document.
    const wrapper = mount(KinetixRoleEditorModal, {
        attachTo: document.body,
        props: {
            open: false,
            role: options.role ?? null,
            features: [],
            canCreateGlobal: options.canCreateGlobal ?? false,
        },
        global: { plugins: [i18n] },
    });

    await wrapper.setProps({ open: true });
    await wrapper.vm.$nextTick();

    return wrapper;
}

const globalToggle = () =>
    Array.from(document.querySelectorAll('label')).find((label) =>
        label.textContent?.includes('Global role'),
    );

describe('KinetixRoleEditorModal — global toggle', () => {
    it('hides the toggle for non-super-admins', async () => {
        const wrapper = await mountModal({ canCreateGlobal: false });

        expect(globalToggle()).toBeUndefined();
        wrapper.unmount();
    });

    it('hides the toggle when editing (a role cannot change teams)', async () => {
        const wrapper = await mountModal({
            canCreateGlobal: true,
            role: { id: 5, name: 'writers', permissions: [] },
        });

        expect(globalToggle()).toBeUndefined();
        wrapper.unmount();
    });

    it('emits global:true when a super-admin checks it on create', async () => {
        const wrapper = await mountModal({ canCreateGlobal: true });

        const toggle = globalToggle();
        expect(toggle).toBeDefined();

        // Check the box, name the role, submit.
        (toggle!.querySelector('button, input') as HTMLElement).click();
        await wrapper.vm.$nextTick();

        const name = document.querySelector(
            '#kx-role-matrix-name',
        ) as HTMLInputElement;
        name.value = 'auditor';
        name.dispatchEvent(new Event('input'));
        await wrapper.vm.$nextTick();

        (document.querySelector('form') as HTMLFormElement).dispatchEvent(
            new Event('submit', { cancelable: true }),
        );
        await wrapper.vm.$nextTick();

        const saved = wrapper.emitted('save')?.[0]?.[0] as KinetixRole & {
            global?: boolean;
        };

        expect(saved).toBeDefined();
        expect(saved.name).toBe('auditor');
        expect(saved.global).toBe(true);
        wrapper.unmount();
    });

    it('omits the flag when the toggle stays unchecked', async () => {
        const wrapper = await mountModal({ canCreateGlobal: true });

        const name = document.querySelector(
            '#kx-role-matrix-name',
        ) as HTMLInputElement;
        name.value = 'writers';
        name.dispatchEvent(new Event('input'));
        await wrapper.vm.$nextTick();

        (document.querySelector('form') as HTMLFormElement).dispatchEvent(
            new Event('submit', { cancelable: true }),
        );
        await wrapper.vm.$nextTick();

        const saved = wrapper.emitted('save')?.[0]?.[0] as KinetixRole & {
            global?: boolean;
        };

        expect(saved.global).toBeUndefined();
        wrapper.unmount();
    });
});
