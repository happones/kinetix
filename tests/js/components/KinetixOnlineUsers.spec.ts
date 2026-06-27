import { mount } from '@vue/test-utils';
import { defineComponent, h, nextTick } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { createI18n } from 'vue-i18n';

const pageProps = {
    kinetix_presence: { enabled: true, channel: 'kinetix-presence' },
};
vi.mock('@inertiajs/vue3', () => ({ usePage: () => ({ props: pageProps }) }));

// Fake presence channel that records here/joining/leaving callbacks so tests
// can drive membership changes.
const cb: Record<string, (...a: any[]) => void> = {};
const fakeChannel = {
    here(fn: (list: unknown[]) => void) {
        cb.here = fn;
        return fakeChannel;
    },
    joining(fn: (u: unknown) => void) {
        cb.joining = fn;
        return fakeChannel;
    },
    leaving(fn: (u: unknown) => void) {
        cb.leaving = fn;
        return fakeChannel;
    },
};
const leaveMock = vi.fn();
vi.mock('@laravel/echo-vue', () => ({
    useEchoPresence: () => ({ channel: () => fakeChannel, leave: leaveMock }),
}));

import KinetixOnlineUsers from '@/components/KinetixOnlineUsers.vue';
import { useKinetixPresence } from '@/composables/useKinetixPresence';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    missing: (_l: string, key: string) => key,
    messages: { en: { kinetix: { presence_online: '{count} online' } } },
});

beforeEach(() => {
    delete cb.here;
    delete cb.joining;
    delete cb.leaving;
    leaveMock.mockReset();
});

const Harness = defineComponent({
    setup(_, { expose }) {
        expose(useKinetixPresence());
        return () => h('div');
    },
});
const mountComposable = () => mount(Harness, { global: { plugins: [i18n] } });

describe('useKinetixPresence', () => {
    it('tracks here / joining / leaving membership', () => {
        const vm = mountComposable().vm as any;

        cb.here([
            { id: 1, name: 'Ada', avatar: null },
            { id: 2, name: 'Grace', avatar: null },
        ]);
        expect(vm.count).toBe(2);
        expect(vm.isOnline(1)).toBe(true);

        cb.joining({ id: 3, name: 'Linus', avatar: null });
        expect(vm.count).toBe(3);
        expect(vm.isOnline('3')).toBe(true);

        cb.leaving({ id: 1, name: 'Ada', avatar: null });
        expect(vm.count).toBe(2);
        expect(vm.isOnline(1)).toBe(false);
    });
});

describe('KinetixOnlineUsers', () => {
    it('renders a facepile with overflow and count', async () => {
        const w = mount(KinetixOnlineUsers, {
            props: { max: 2 },
            global: { plugins: [i18n] },
        });

        cb.here([
            { id: 1, name: 'Ada Lovelace', avatar: null },
            { id: 2, name: 'Grace Hopper', avatar: null },
            { id: 3, name: 'Linus Torvalds', avatar: null },
        ]);
        await nextTick();

        // Initials for the first two, "+1" overflow, and the count label.
        expect(w.text()).toContain('AL');
        expect(w.text()).toContain('+1');
        expect(w.text()).toContain('3 online');
    });
});
