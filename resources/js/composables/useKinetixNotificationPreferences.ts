import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type {
    KinetixNotificationPreferences,
    KinetixSharedProps,
} from '@/types/kinetix';

/**
 * Self-service notification preferences: load the type × channel matrix and
 * toggle individual cells. Changes are applied optimistically and persisted.
 * A type's mandatory channels are locked on: `isMandatory()` tells them apart
 * and `set()` never posts for one.
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

            // A mandatory channel is always on, whatever an older payload says.
            for (const row of matrix.value.types) {
                for (const channel of row.mandatory ?? []) {
                    if (channel in row.channels) {
                        row.channels[channel] = true;
                    }
                }
            }
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

        // A mandatory channel cannot be turned off: nothing to persist (the
        // server would answer 422).
        if (row?.mandatory?.includes(channel)) {
            return;
        }

        if (row) {
            row.channels[channel] = enabled;
        }

        await kinetixFetch(base(), {
            method: 'POST',
            body: { type, channel, enabled },
        });
    }

    /** Whether the user cannot turn this channel off for the type (set in code). */
    function isMandatory(type: string, channel: string): boolean {
        return (
            matrix.value.types
                .find((t) => t.key === type)
                ?.mandatory?.includes(channel) ?? false
        );
    }

    return { matrix, loading, load, set, isMandatory };
}
