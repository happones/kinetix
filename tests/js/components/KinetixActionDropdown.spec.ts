import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, ref } from 'vue';
import { createI18n } from 'vue-i18n';

const requestAction = vi.fn();

vi.mock('@/composables/useKinetixActions', () => ({
    useActionConfirmation: () => ({
        pendingAction: ref(null),
        isConfirmOpen: ref(false),
        processing: ref(false),
        processingAction: ref(null),
        requestAction,
        confirm: vi.fn(),
        cancel: vi.fn(),
    }),
}));

import KinetixActionDropdown from '@/components/KinetixActionDropdown.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: {
        en: {
            kinetix: {
                more_actions: 'More actions',
            },
        },
    },
});

const GROUP = {
    type: 'group',
    name: 'row-actions',
    label: null,
    icon: null,
    actions: [
        { type: 'action', name: 'edit', label: 'Edit', modal: 'edit' },
        { type: 'action', name: 'ping', label: 'Ping', url: null },
    ],
} as any;

const RECORD = { id: 7, values: { name: 'Ada' }, actions: [] } as any;

async function mountAndOpen(props: Record<string, unknown> = {}, attrs = {}) {
    const wrapper = mount(KinetixActionDropdown, {
        props: { group: GROUP, ...props },
        attrs,
        global: { plugins: [i18n] },
        attachTo: document.body,
    });

    // Reka's dropdown opens on pointerdown; the content teleports to body.
    const trigger = wrapper.get('button');
    await trigger.trigger('pointerdown', { button: 0, pointerType: 'mouse' });
    await trigger.trigger('click');
    await nextTick();

    const items = Array.from(
        document.body.querySelectorAll('[role="menuitem"]'),
    );

    expect(items.length).toBeGreaterThan(0);

    return { wrapper, items };
}

async function selectItem(item: Element): Promise<void> {
    // Reka menu items select on pointerup/click of the item element.
    item.dispatchEvent(
        new Event('pointerup', { bubbles: true, cancelable: true }),
    );
    item.dispatchEvent(
        new MouseEvent('click', { bubbles: true, cancelable: true }),
    );
    await nextTick();
}

describe('KinetixActionDropdown', () => {
    beforeEach(() => {
        requestAction.mockClear();
    });

    afterEach(() => {
        document.body.innerHTML = '';
    });

    it('emits action-click with the record when a host listens — grouped modal actions reach the host instead of dying internally', async () => {
        const onActionClick = vi.fn();

        const { wrapper, items } = await mountAndOpen(
            { record: RECORD },
            { onActionClick },
        );

        await selectItem(items[0]);

        expect(onActionClick).toHaveBeenCalledTimes(1);
        expect(onActionClick).toHaveBeenCalledWith(
            expect.objectContaining({ name: 'edit', modal: 'edit' }),
            expect.objectContaining({ id: 7 }),
        );
        // The host owns execution — the internal path must stay quiet.
        expect(requestAction).not.toHaveBeenCalled();

        wrapper.unmount();
    });

    it('falls back to internal execution when no host listens (standalone use), forwarding the record as extra data', async () => {
        const { wrapper, items } = await mountAndOpen({ record: RECORD });

        await selectItem(items[1]);

        expect(requestAction).toHaveBeenCalledTimes(1);
        expect(requestAction).toHaveBeenCalledWith(
            expect.objectContaining({ name: 'ping' }),
            expect.objectContaining({
                record: expect.objectContaining({ id: 7 }),
            }),
        );

        wrapper.unmount();
    });
});
