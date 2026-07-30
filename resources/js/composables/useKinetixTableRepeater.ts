import { usePage } from '@inertiajs/vue3';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixSharedProps } from '@/types/kinetix';

/**
 * Autosave persistence for <KinetixTableRepeater> rows. Each call posts the
 * field's signed descriptor (`token`) so the server can only write the declared
 * columns on the bound relation.
 */
export function useKinetixTableRepeater() {
    const page = usePage<KinetixSharedProps>();
    const url = (): string =>
        `/${kinetixRoutePrefix(page)}/tables/table-repeater`;

    async function create(
        token: string,
        values: Record<string, unknown>,
    ): Promise<number | string | null> {
        const res = await kinetixFetch<{ id: number | string }>(url(), {
            method: 'POST',
            body: { token, values },
        });

        return res?.id ?? null;
    }

    async function update(
        token: string,
        id: number | string,
        values: Record<string, unknown>,
    ): Promise<void> {
        await kinetixFetch(url(), {
            method: 'PUT',
            body: { token, id, values },
        });
    }

    async function remove(token: string, id: number | string): Promise<void> {
        await kinetixFetch(url(), {
            method: 'DELETE',
            body: { token, id },
        });
    }

    return { create, update, remove };
}
