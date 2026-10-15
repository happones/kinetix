import { flushPromises, mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import KinetixActivityLog from '@/components/KinetixActivityLog.vue';
import { i18n } from './i18n';

const loadMock = vi.fn();
vi.mock('@/composables/useKinetixActivity', async () => {
    const { ref } = await import('vue');

    return {
        useKinetixActivity: () => ({ loading: ref(false), load: loadMock }),
    };
});

const entry = (changes: {
    old: Record<string, unknown>;
    attributes: Record<string, unknown>;
}) => ({
    id: 1,
    event: 'updated',
    description: null,
    causerName: 'Ada',
    causerId: 1,
    subjectType: 'product',
    subjectId: 7,
    changes,
    createdAt: '2026-10-14T19:59:21Z',
});

const mountWith = async (changes: Parameters<typeof entry>[0]) => {
    loadMock.mockResolvedValueOnce({
        data: [entry(changes)],
        pagination: { current_page: 1, last_page: 1 },
    });

    const wrapper = mount(KinetixActivityLog, {
        global: { plugins: [i18n] },
    });
    await flushPromises();

    return wrapper;
};

describe('KinetixActivityLog', () => {
    it('says an empty value with a dash and JSON without escaped slashes', async () => {
        const wrapper = await mountWith({
            old: { gallery: null },
            attributes: {
                gallery:
                    '[{"path":"products\\/gallery\\/a.jpg","name":"a.jpg"}]',
            },
        });

        const line = wrapper.get('li li').text();
        expect(line).toBe(
            'gallery: — → [{"path":"products/gallery/a.jpg","name":"a.jpg"}]',
        );
        expect(line).not.toContain('\\/');
    });

    it('shortens a long value and keeps the whole one in its tooltip', async () => {
        const long = 'x'.repeat(200);
        const wrapper = await mountWith({
            old: { notes: 'short' },
            attributes: { notes: long },
        });

        const item = wrapper.get('li li');
        expect(item.text().length).toBeLessThan(120);
        expect(item.text()).toContain('…');
        expect(item.attributes('title')).toBe(`notes: short → ${long}`);
    });

    it('leaves a short change as it is, without a tooltip', async () => {
        const wrapper = await mountWith({
            old: { slug: 'old-slug' },
            attributes: { slug: 'new-slug' },
        });

        const item = wrapper.get('li li');
        expect(item.text()).toBe('slug: old-slug → new-slug');
        expect(item.attributes('title')).toBeUndefined();
    });
});
