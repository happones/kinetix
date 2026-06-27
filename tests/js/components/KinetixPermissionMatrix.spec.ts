import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import type { KinetixPermissionFeature } from '@/types';
import KinetixPermissionMatrix from '@/components/KinetixPermissionMatrix.vue';
import { i18n } from './i18n';

const features: KinetixPermissionFeature[] = [
    {
        name: 'posts',
        label: 'Posts',
        abilities: [
            { key: 'view', label: 'View', permission: 'posts.view' },
            { key: 'update', label: 'Update', permission: 'posts.update' },
        ],
    },
];

function mountMatrix(modelValue: string[] = []) {
    return mount(KinetixPermissionMatrix, {
        props: { features, modelValue },
        global: { plugins: [i18n] },
    });
}

describe('KinetixPermissionMatrix', () => {
    it("renders each feature's abilities and permission keys", () => {
        const wrapper = mountMatrix();

        expect(wrapper.text()).toContain('Posts');
        expect(wrapper.text()).toContain('posts.view');
        expect(wrapper.text()).toContain('posts.update');
    });

    it('emits the toggled permission when an ability is checked', async () => {
        const wrapper = mountMatrix([]);
        const boxes = wrapper.findAll('[role="checkbox"]');

        // [0] = select-all, [1] = posts.view, [2] = posts.update
        await boxes[1].trigger('click');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([
            ['posts.view'],
        ]);
    });

    it('select-all emits every permission of the feature', async () => {
        const wrapper = mountMatrix([]);
        const boxes = wrapper.findAll('[role="checkbox"]');

        await boxes[0].trigger('click');

        expect(wrapper.emitted('update:modelValue')?.[0]).toEqual([
            ['posts.view', 'posts.update'],
        ]);
    });

    it('filters by search query', async () => {
        const wrapper = mountMatrix();

        await wrapper.get('input[type="text"]').setValue('nonexistent');

        expect(wrapper.text()).not.toContain('posts.view');
    });
});
