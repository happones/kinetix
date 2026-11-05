import { flushPromises, mount } from '@vue/test-utils';
import { beforeAll, beforeEach, describe, expect, it, vi } from 'vitest';
import { ref } from 'vue';
import { createI18n } from 'vue-i18n';

const unlockMock = vi.fn();
const lockMock = vi.fn();
const state = {
    config: ref({
        enabled: true,
        ttlMinutes: 5,
        unlockedUntil: null as string | null,
    }),
    isUnlocked: ref(false),
    remainingSeconds: ref(0),
    dialogOpen: ref(false),
};

// The host decides from the page props; the widget from the composable.
vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({
        props: {
            get kinetix_confidential() {
                return state.config.value;
            },
        },
    }),
}));

vi.mock('@/composables/useKinetixConfidential', () => ({
    useKinetixConfidential: () => ({
        config: state.config,
        isUnlocked: state.isUnlocked,
        remainingSeconds: state.remainingSeconds,
        unlock: unlockMock,
        lock: lockMock,
        dialogOpen: state.dialogOpen,
    }),
}));

import KinetixConfidentialUnlock from '@/components/KinetixConfidentialUnlock.vue';

const i18n = createI18n({
    legacy: false,
    locale: 'en',
    missingWarn: false,
    messages: {
        en: {
            kinetix: {
                confidential_unlock: 'Unlock',
                confidential_lock: 'Lock',
                confidential_password_label: 'Password',
                confidential_password_incorrect: 'Incorrect password.',
                cancel: 'Cancel',
            },
        },
    },
});

const mountIt = () =>
    mount(KinetixConfidentialUnlock, { global: { plugins: [i18n] } });

describe('KinetixConfidentialUnlock', () => {
    // The widget is a lazy chunk: load it once, through the host, so the
    // mounts below render it synchronously as before.
    beforeAll(async () => {
        const w = mountIt();
        await vi.waitFor(() => expect(w.find('button').exists()).toBe(true));
        w.unmount();
    });

    it('holds an icon-button-sized, hidden placeholder while the widget loads', async () => {
        vi.resetModules();
        const { default: Fresh } =
            await import('@/components/KinetixConfidentialUnlock.vue');
        const w = mount(Fresh, { global: { plugins: [i18n] } });

        const placeholder = w.find(
            '[data-kinetix-lazy-skeleton="icon-button"]',
        );
        expect(placeholder.exists()).toBe(true);
        expect(placeholder.attributes('aria-hidden')).toBe('true');
        expect(placeholder.classes()).toContain('size-8');

        await vi.waitFor(() =>
            expect(w.find('button[aria-label="Unlock"]').exists()).toBe(true),
        );
        w.unmount();
    });

    beforeEach(() => {
        unlockMock.mockReset();
        lockMock.mockReset();
        state.config.value = {
            enabled: true,
            ttlMinutes: 5,
            unlockedUntil: null,
        };
        state.isUnlocked.value = false;
        state.remainingSeconds.value = 0;
        state.dialogOpen.value = false;
    });

    it('renders nothing when the module is disabled', async () => {
        state.config.value = {
            enabled: false,
            ttlMinutes: 5,
            unlockedUntil: null,
        };

        const w = mountIt();
        await flushPromises();

        expect(w.find('button').exists()).toBe(false);
        expect(
            w.findComponent({ name: 'ConfidentialUnlockBody' }).exists(),
        ).toBe(false);
        expect(w.find('[data-kinetix-lazy-skeleton]').exists()).toBe(false);
    });

    it('shows a locked trigger button when not unlocked', () => {
        const w = mountIt();

        expect(w.find('button[aria-label="Unlock"]').exists()).toBe(true);
    });

    it('clicking the locked trigger opens the shared dialog state', async () => {
        const w = mountIt();
        await w.find('button[aria-label="Unlock"]').trigger('click');

        expect(state.dialogOpen.value).toBe(true);
    });

    it('shows an unlocked button with the countdown when unlocked', () => {
        state.isUnlocked.value = true;
        state.remainingSeconds.value = 125;

        const w = mountIt();

        expect(w.text()).toContain('2:05');
    });

    it('clicking the unlocked button calls lock()', async () => {
        state.isUnlocked.value = true;
        state.remainingSeconds.value = 60;

        const w = mountIt();
        await w.find('button[aria-label="Lock"]').trigger('click');

        expect(lockMock).toHaveBeenCalled();
    });
});
