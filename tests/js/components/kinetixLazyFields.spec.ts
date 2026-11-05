import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: { errors: {} } }),
    router: { visit: vi.fn(), reload: vi.fn() },
}));

import {
    KINETIX_FORM_FIELD_LOADERS,
    KINETIX_SELECT_CONTROL_LOADERS,
} from '@/components/Form/kinetixFormFieldLoaders';
import SelectField from '@/components/Form/fields/SelectField.vue';
import KinetixFormField from '@/components/Form/KinetixFormField.vue';
import KinetixForm from '@/components/KinetixForm.vue';
import { KINETIX_TABLE_FILTER_LOADERS } from '@/components/Table/kinetixTableFilterLoaders';
import KinetixTableFilterField from '@/components/Table/KinetixTableFilterField.vue';
import KinetixTableFilters from '@/components/Table/KinetixTableFilters.vue';
import type { KinetixLazyComponent } from '@/composables/kinetixLazy';
import { i18n } from './i18n';

/**
 * The lazy side of forms and table filters, with nothing preloaded: this file
 * is the one place that watches the code arrive instead of assuming it.
 */

afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

/** Spy on every entry's preload (calling through), keyed by type. */
const spyPreloads = (loaders: Record<string, KinetixLazyComponent>) => {
    const spies = new Map<KinetixLazyComponent, ReturnType<typeof vi.fn>>();

    for (const entry of new Set(Object.values(loaders))) {
        spies.set(entry, vi.spyOn(entry, 'preload') as never);
    }

    return (type: string) => spies.get(loaders[type])!;
};

const skeleton = (root: ParentNode = document.body) =>
    root.querySelector('[data-kinetix-lazy-skeleton]');

describe('lazy form field types', () => {
    it('a searchable select shows the field skeleton, then the combobox', async () => {
        const controlPreload = spyPreloads(KINETIX_SELECT_CONTROL_LOADERS);
        const wrapper = mount(KinetixForm, {
            props: {
                form: {
                    schema: [
                        {
                            type: 'select',
                            name: 'city',
                            label: 'City',
                            isSearchable: true,
                            options: { a: 'A' },
                        },
                    ],
                    data: {},
                    rules: {},
                    operation: 'create',
                },
            },
            attachTo: document.body,
            global: { plugins: [i18n] },
        });

        // The walk picks the combobox by the node's isSearchable.
        expect(controlPreload('combobox')).toHaveBeenCalledTimes(1);
        expect(controlPreload('select')).not.toHaveBeenCalled();
        expect(
            wrapper.find('[data-kinetix-lazy-skeleton="field"]').exists(),
        ).toBe(true);

        const Combobox =
            await KINETIX_SELECT_CONTROL_LOADERS.combobox.preload();
        await flushPromises();

        expect(wrapper.findComponent(Combobox).exists()).toBe(true);
        expect(wrapper.findComponent(SelectField).exists()).toBe(true);
        expect(skeleton(wrapper.element)).toBeNull();
        wrapper.unmount();
    });

    it('a form of core types loads no lazy field type, only its select control', async () => {
        const preloadOf = spyPreloads(KINETIX_FORM_FIELD_LOADERS);
        const controlPreload = spyPreloads(KINETIX_SELECT_CONTROL_LOADERS);

        const wrapper = mount(KinetixForm, {
            props: {
                form: {
                    schema: [
                        { type: 'text-input', name: 'title', label: 'Title' },
                        { type: 'select', name: 'kind', label: 'Kind' },
                        { type: 'checkbox', name: 'ok', label: 'Ok' },
                        { type: 'toggle', name: 'on', label: 'On' },
                        { type: 'textarea', name: 'body', label: 'Body' },
                        { type: 'hidden', name: 'secret' },
                    ],
                    data: {},
                    rules: {},
                    operation: 'create',
                },
            },
            global: {
                plugins: [i18n],
                stubs: { KinetixSelect: true, KinetixCombobox: true },
            },
        });
        await flushPromises();

        for (const type of Object.keys(KINETIX_FORM_FIELD_LOADERS)) {
            expect(preloadOf(type), type).not.toHaveBeenCalled();
            expect(KINETIX_FORM_FIELD_LOADERS[type].loaded, type).toBe(false);
        }

        // The plain select renders the Select control, started at setup.
        expect(controlPreload('select')).toHaveBeenCalledTimes(1);
        await KINETIX_SELECT_CONTROL_LOADERS.select.preload();
        await flushPromises();

        expect(skeleton(wrapper.element)).toBeNull();
    });

    it('starts every lazy type a schema uses in one pass at form setup', () => {
        const preloadOf = spyPreloads(KINETIX_FORM_FIELD_LOADERS);

        const wrapper = mount(KinetixForm, {
            props: {
                form: {
                    schema: [
                        { type: 'date-picker', name: 'due', label: 'Due' },
                        {
                            type: 'wizard',
                            schema: [
                                {
                                    type: 'wizard-step',
                                    heading: 'Body',
                                    schema: [
                                        {
                                            type: 'section',
                                            schema: [
                                                {
                                                    type: 'rich-editor',
                                                    name: 'body',
                                                    label: 'Body',
                                                },
                                            ],
                                        },
                                    ],
                                },
                            ],
                        },
                    ],
                    data: {},
                    rules: {},
                    operation: 'create',
                },
            },
            global: { plugins: [i18n] },
        });

        // Synchronously, before any of them has rendered or resolved.
        expect(preloadOf('date-picker')).toHaveBeenCalledTimes(1);
        expect(preloadOf('wizard')).toHaveBeenCalledTimes(1);
        expect(preloadOf('rich-editor')).toHaveBeenCalledTimes(1);
        expect(preloadOf('slider')).not.toHaveBeenCalled();
        expect(preloadOf('media-library')).not.toHaveBeenCalled();

        wrapper.unmount();
    });

    it.each(
        Object.keys(KINETIX_FORM_FIELD_LOADERS).filter(
            (type) => type !== 'wizard',
        ),
    )('renders a %s field once its code arrives', async (type) => {
        const entry = KINETIX_FORM_FIELD_LOADERS[type];
        const wrapper = mount(KinetixFormField, {
            props: {
                comp: { type, name: 'field', label: 'Field', schema: [] },
                values: {},
                errors: {},
            },
            attachTo: document.body,
            global: {
                plugins: [i18n],
                config: { warnHandler: () => {} },
            },
        });

        const real = await entry.preload();
        await flushPromises();

        expect(wrapper.findComponent(real).exists()).toBe(true);
        expect(skeleton(wrapper.element.parentElement!)).toBeNull();
        wrapper.unmount();
    });
});

describe('lazy table filter types', () => {
    const filter = (type: string) =>
        ({
            name: 'f',
            label: 'F',
            type,
            default: null,
            options: { a: 'A' },
            schema: [{ type: 'text-input', name: 'q', label: 'Q' }],
        }) as never;

    it('renders the date filter when the popover is opened by keyboard alone', async () => {
        const wrapper = mount(KinetixTableFilters, {
            props: { filters: [filter('date')], activeFilters: {} },
            attachTo: document.body,
            global: { plugins: [i18n] },
        });

        // Enter on a focused button activates it as a click; no pointer ever
        // hovered the trigger.
        await wrapper.get('button').trigger('click');

        await vi.waitFor(async () => {
            await flushPromises();
            expect(
                document.querySelector('[data-kinetix-lazy-skeleton]'),
            ).toBeNull();
            expect(
                wrapper
                    .findComponent(
                        await KINETIX_TABLE_FILTER_LOADERS.date.preload(),
                    )
                    .exists(),
            ).toBe(true);
        });
        wrapper.unmount();
    });

    it('does not download filter inputs until someone shows intent', async () => {
        const preloadOf = spyPreloads(KINETIX_TABLE_FILTER_LOADERS);
        const wrapper = mount(KinetixTableFilters, {
            props: { filters: [filter('address')], activeFilters: {} },
            attachTo: document.body,
            global: { plugins: [i18n] },
        });
        await flushPromises();

        expect(preloadOf('address')).not.toHaveBeenCalled();

        await wrapper.get('button').trigger('focusin');
        expect(preloadOf('address')).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });

    it.each(Object.keys(KINETIX_TABLE_FILTER_LOADERS))(
        'renders a %s filter once its code arrives',
        async (type) => {
            const wrapper = mount(KinetixTableFilterField, {
                props: { filter: filter(type), value: null },
                attachTo: document.body,
                global: {
                    plugins: [i18n],
                    stubs: { KinetixSelect: true, KinetixCombobox: true },
                    config: { warnHandler: () => {} },
                },
            });

            const real = await KINETIX_TABLE_FILTER_LOADERS[type].preload();
            await flushPromises();

            expect(wrapper.findComponent(real).exists()).toBe(true);
            wrapper.unmount();
        },
    );
});
