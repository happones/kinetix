import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { defineComponent, h } from 'vue';

const routerPost = vi.fn();

vi.mock('@inertiajs/vue3', () => ({
    router: { post: (...args: unknown[]) => routerPost(...args) },
    usePage: () => ({ props: { errors: {} } }),
}));

import { useKinetixFormActions } from '@/composables/useKinetixFormActions';

const formAction = (overrides: Record<string, unknown> = {}) =>
    ({
        name: 'rename-widget',
        label: 'Rename',
        isFormAction: true,
        form: {
            schema: [],
            data: { name: 'Old' },
            rules: {},
            operation: 'rename-widget',
        },
        ...overrides,
    }) as any;

const mountComposable = (
    descriptor: () => string | null | undefined = () => 'DESCRIPTOR',
) => {
    let api: ReturnType<typeof useKinetixFormActions>;

    const Harness = defineComponent({
        setup() {
            api = useKinetixFormActions({
                descriptor,
                routePrefix: () => '_kinetix',
            });

            return () => h('div');
        },
    });

    mount(Harness);

    return api!;
};

describe('useKinetixFormActions', () => {
    it('ignores an action that is not a form action', () => {
        const api = mountComposable();

        const handled = api.handleFormAction({ name: 'x' } as any);

        expect(handled).toBe(false);
        expect(api.isOpen.value).toBe(false);
    });

    it('ignores a form action when no descriptor is sealed', () => {
        const api = mountComposable(() => null);

        const handled = api.handleFormAction(formAction());

        expect(handled).toBe(false);
        expect(api.isOpen.value).toBe(false);
    });

    it('opens the modal and clones the shipped schema', () => {
        const api = mountComposable();
        const action = formAction();

        const handled = api.handleFormAction(action, { id: 7 } as any);

        expect(handled).toBe(true);
        expect(api.isOpen.value).toBe(true);
        expect(api.activeAction.value?.name).toBe('rename-widget');
        // Cloned, not the same object reference.
        expect(api.activeForm.value).not.toBe(action.form);
        expect(api.activeForm.value.data).toEqual({ name: 'Old' });
    });

    it('posts descriptor + action + recordId + data for a record action', () => {
        routerPost.mockClear();
        const api = mountComposable();
        api.handleFormAction(formAction(), { id: 7 } as any);

        api.submitForm({ name: 'New' });

        expect(routerPost).toHaveBeenCalledTimes(1);
        const [url, body] = routerPost.mock.calls[0];
        expect(url).toBe('/_kinetix/tables/form-action');
        expect(body).toEqual({
            descriptor: 'DESCRIPTOR',
            action: 'rename-widget',
            data: { name: 'New' },
            recordId: 7,
        });
    });

    it('omits recordId for a toolbar action', () => {
        routerPost.mockClear();
        const api = mountComposable();
        api.handleFormAction(formAction());

        api.submitForm({ name: 'New' });

        const [, body] = routerPost.mock.calls[0];
        expect(body).not.toHaveProperty('recordId');
    });

    it('closes and drops the form on cancel', () => {
        const api = mountComposable();
        api.handleFormAction(formAction(), { id: 7 } as any);

        api.closeForm();

        expect(api.isOpen.value).toBe(false);
        expect(api.activeForm.value).toBeNull();
        expect(api.activeAction.value).toBeNull();
    });
});
