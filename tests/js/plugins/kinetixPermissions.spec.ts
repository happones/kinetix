import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent, h, reactive, ref, withDirectives } from 'vue';
import type { Directive, Ref } from 'vue';
import type { KinetixPermissionState } from '@/types/kinetix';

// A reactive stand-in for Inertia's page store, so a spec can swap the shared
// props the way a visit / partial reload does.
const page = reactive<{
    props: { kinetix_permissions?: KinetixPermissionState };
}>({ props: {} });

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => page,
}));

import { KinetixPermissions } from '@/plugins/kinetixPermissions';

function grant(state?: KinetixPermissionState): void {
    page.props = state ? { kinetix_permissions: state } : {};
}

describe('v-can directive', () => {
    let directive: Directive;

    beforeEach(() => {
        grant(undefined);
        const app = { directive: vi.fn() };
        KinetixPermissions.install(app as never);
        directive = app.directive.mock.calls[0][1] as Directive;
    });

    function render(value: Ref<string | string[]>, tick?: Ref<number>) {
        return mount(
            defineComponent({
                setup: () => () => {
                    void tick?.value;

                    return withDirectives(
                        h('button', { type: 'button' }, 'Go'),
                        [[directive, value.value]],
                    );
                },
            }),
        );
    }

    it('shows the element when the permission is granted and hides it otherwise', () => {
        grant({
            enabled: true,
            permissions: ['posts.create'],
            roles: [],
        });

        expect(
            render(ref('posts.create')).get('button').element.style.display,
        ).toBe('');
        expect(
            render(ref('posts.delete')).get('button').element.style.display,
        ).toBe('none');
    });

    it('treats an array as any-of', () => {
        grant({
            enabled: true,
            permissions: ['posts.view'],
            roles: [],
        });

        expect(
            render(ref(['posts.update', 'posts.view'])).get('button').element
                .style.display,
        ).toBe('');
        expect(
            render(ref(['posts.update', 'posts.delete'])).get('button').element
                .style.display,
        ).toBe('none');
        expect(render(ref([])).get('button').element.style.display).toBe(
            'none',
        );
    });

    it('shows everything to a super-admin who holds no explicit permissions', () => {
        grant({
            enabled: true,
            permissions: [],
            roles: ['super-admin'],
            isSuperAdmin: true,
        });

        expect(
            render(ref('anything.at.all')).get('button').element.style.display,
        ).toBe('');
        expect(
            render(ref(['x.y', 'a.b'])).get('button').element.style.display,
        ).toBe('');
    });

    it('denies when the kinetix_permissions prop is missing', () => {
        grant(undefined);

        expect(
            render(ref('posts.create')).get('button').element.style.display,
        ).toBe('none');
    });

    it('re-evaluates on update when the bound value changes', async () => {
        grant({
            enabled: true,
            permissions: ['posts.view'],
            roles: [],
        });
        const value = ref<string | string[]>('posts.delete');
        const wrapper = render(value);

        expect(wrapper.get('button').element.style.display).toBe('none');

        value.value = 'posts.view';
        await wrapper.vm.$nextTick();

        expect(wrapper.get('button').element.style.display).toBe('');
    });

    it('re-evaluates on update when Inertia replaces the shared props', async () => {
        grant({
            enabled: true,
            permissions: [],
            roles: [],
        });
        const tick = ref(0);
        const wrapper = render(ref('posts.delete'), tick);

        expect(wrapper.get('button').element.style.display).toBe('none');

        grant({
            enabled: true,
            permissions: [],
            roles: ['super-admin'],
            isSuperAdmin: true,
        });
        tick.value++;
        await wrapper.vm.$nextTick();

        expect(wrapper.get('button').element.style.display).toBe('');

        grant({
            enabled: true,
            permissions: [],
            roles: [],
        });
        tick.value++;
        await wrapper.vm.$nextTick();

        expect(wrapper.get('button').element.style.display).toBe('none');
    });

    it('works through the installed plugin in a template', () => {
        grant({
            enabled: true,
            permissions: ['posts.create'],
            roles: [],
        });

        const wrapper = mount(
            {
                template:
                    '<div><button v-can="\'posts.create\'">New</button><a v-can="[\'posts.delete\']">Delete</a></div>',
            },
            { global: { plugins: [KinetixPermissions] } },
        );

        expect(wrapper.get('button').element.style.display).toBe('');
        expect(wrapper.get('a').element.style.display).toBe('none');
    });
});
