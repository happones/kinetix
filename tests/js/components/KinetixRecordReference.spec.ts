import { flushPromises, mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { i18n } from './i18n';
import KinetixPageHeader from '@/components/KinetixPageHeader.vue';
import KinetixRecordReference from '@/components/KinetixRecordReference.vue';
import type { KinetixRecordReference as Reference } from '@/types/kinetix';

const writeText = vi.fn();

const ULID = '01JD8XKQ4M7T2R9VBN3C5H6PZW';

const reference = (overrides: Partial<Reference> = {}): Reference => ({
    visible: '6PZW',
    position: 'end',
    label: 'Order',
    copy: `Order: ${ULID}`,
    ...overrides,
});

const mountIt = (value: Reference | null) =>
    mount(KinetixRecordReference, {
        props: { reference: value },
        attachTo: document.body,
        global: { plugins: [i18n] },
    });

beforeEach(() => {
    writeText.mockReset().mockResolvedValue(undefined);
    Object.defineProperty(navigator, 'clipboard', {
        value: { writeText },
        configurable: true,
    });
});

describe('KinetixRecordReference', () => {
    it('shows the label and the last characters, and copies the full text', async () => {
        const w = mountIt(reference());
        const button = w.get('button');

        expect(button.attributes('type')).toBe('button');
        expect(w.get('[aria-hidden="true"]').text()).toMatch(
            /Order\s*·\s*…6PZW/,
        );
        expect(w.text()).not.toContain(ULID);

        await button.trigger('click');
        expect(writeText).toHaveBeenCalledWith(`Order: ${ULID}`);
        w.unmount();
    });

    it('speaks the reference instead of the ellipsis and middle dot', () => {
        const w = mountIt(reference());

        expect(w.get('.sr-only').text()).toBe('Order reference ending in 6PZW');
        w.unmount();
    });

    it('puts the ellipsis after a leading part and none on a whole value', () => {
        const start = mountIt(
            reference({ visible: '01JD8X', position: 'start' }),
        );
        expect(start.text()).toContain('01JD8X…');
        expect(start.get('.sr-only').text()).toBe(
            'Order reference starting with 01JD8X',
        );
        start.unmount();

        const full = mountIt(
            reference({
                visible: 'summer-sale',
                position: 'full',
                label: null,
            }),
        );
        expect(full.text()).not.toContain('…');
        expect(full.text()).not.toContain('·');
        expect(full.get('.sr-only').text()).toBe(
            'Record reference summer-sale',
        );
        full.unmount();
    });

    it('renders nothing without a reference', () => {
        const w = mountIt(null);

        expect(w.find('button').exists()).toBe(false);
        w.unmount();
    });

    it('sits under the page header heading when given', async () => {
        const w = mount(KinetixPageHeader, {
            props: { heading: 'Order details', recordReference: reference() },
            global: {
                plugins: [i18n],
                stubs: { KinetixActionBar: true },
            },
        });

        await flushPromises();

        expect(w.find('[data-kinetix-record-reference]').exists()).toBe(true);
        expect(
            mount(KinetixPageHeader, {
                props: { heading: 'Order details' },
                global: { plugins: [i18n], stubs: { KinetixActionBar: true } },
            })
                .find('[data-kinetix-record-reference]')
                .exists(),
        ).toBe(false);
    });
});
