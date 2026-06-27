import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixBrowserSession, KinetixSharedProps } from '@/types';

/**
 * Self-service browser sessions: list the user's active sessions (requires
 * SESSION_DRIVER=database) and log out every other device. Talks to Kinetix's
 * `sessions` endpoints.
 */
export function useKinetixSessions() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/sessions`;

    const sessions = ref<KinetixBrowserSession[]>([]);
    const databaseDriver = ref(true);
    const requiresPassword = ref(true);
    const loading = ref(false);

    async function load(): Promise<void> {
        loading.value = true;

        try {
            const data = await kinetixFetch<{
                sessions: KinetixBrowserSession[];
                databaseDriver: boolean;
                requiresPassword: boolean;
            }>(base());
            sessions.value = data?.sessions ?? [];
            databaseDriver.value = data?.databaseDriver ?? false;
            requiresPassword.value = data?.requiresPassword ?? false;
        } finally {
            loading.value = false;
        }
    }

    async function logoutOthers(
        password?: string,
    ): Promise<{ status: string; count: number } | null> {
        return kinetixFetch<{ status: string; count: number }>(
            `${base()}/others`,
            {
                method: 'DELETE',
                body: password !== undefined ? { password } : undefined,
            },
        );
    }

    return {
        sessions,
        databaseDriver,
        requiresPassword,
        loading,
        load,
        logoutOthers,
    };
}
