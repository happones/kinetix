import { router, usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixConfidentialConfig, KinetixSharedProps } from '@/types';

/** Shared across every call so a masked-cell lock icon and the header widget open the same dialog. */
const dialogOpen = ref(false);

export function requestConfidentialUnlock(): void {
    dialogOpen.value = true;
}

/**
 * Confidential-fields reveal-gate: reads the `kinetix_confidential` shared
 * prop and unlocks/locks the current session. Server-side truth is always
 * re-checked by `ConfidentialCast` on every request — `remainingSeconds` here
 * is a cosmetic countdown only. `unlock()`/`lock()` trigger a full Inertia
 * reload so any already-rendered masked Table/Infolist values refresh too.
 */
export function useKinetixConfidential() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/confidential`;

    const config = computed<KinetixConfidentialConfig>(
        () =>
            page.props.kinetix_confidential ?? {
                enabled: false,
                ttlMinutes: 5,
                unlockedUntil: null,
            },
    );

    const now = ref(Date.now());
    const timer: ReturnType<typeof setInterval> = setInterval(() => {
        now.value = Date.now();
    }, 1000);

    onUnmounted(() => clearInterval(timer));

    const unlockedUntilMs = computed(() =>
        config.value.unlockedUntil
            ? new Date(config.value.unlockedUntil).getTime()
            : null,
    );

    const isUnlocked = computed(
        () =>
            unlockedUntilMs.value !== null && unlockedUntilMs.value > now.value,
    );

    const remainingSeconds = computed(() => {
        if (unlockedUntilMs.value === null) {
            return 0;
        }

        return Math.max(
            0,
            Math.round((unlockedUntilMs.value - now.value) / 1000),
        );
    });

    async function unlock(password: string): Promise<void> {
        await kinetixFetch(`${base()}/unlock`, {
            method: 'POST',
            body: { password },
        });
        router.reload();
    }

    async function lock(): Promise<void> {
        await kinetixFetch(`${base()}/lock`, { method: 'POST' });
        router.reload();
    }

    return { config, isUnlocked, remainingSeconds, unlock, lock, dialogOpen };
}
