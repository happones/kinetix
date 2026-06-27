import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type {
    KinetixNotificationPreferences,
    KinetixSharedProps,
} from '@/types';

/**
 * Self-service notification preferences: load the type × channel matrix and
 * toggle individual cells. Changes are applied optimistically and persisted.
 */
export function useKinetixNotificationPreferences() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string =>
        `/${kinetixRoutePrefix(page)}/notification-preferences`;

    const matrix = ref<KinetixNotificationPreferences>({
        channels: [],
        types: [],
    });
    const loading = ref(false);

    async function load(): Promise<void> {
        loading.value = true;

        try {
            const data =
                await kinetixFetch<KinetixNotificationPreferences>(base());
            matrix.value = data ?? { channels: [], types: [] };
        } finally {
            loading.value = false;
        }
    }

    async function set(
        type: string,
        channel: string,
        enabled: boolean,
    ): Promise<void> {
        const row = matrix.value.types.find((t) => t.key === type);

        if (row) {
            row.channels[channel] = enabled;
        }

        await kinetixFetch(base(), {
            method: 'POST',
            body: { type, channel, enabled },
        });
    }

    return { matrix, loading, load, set };
}
