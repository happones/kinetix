import { usePage } from '@inertiajs/vue3';
import { ref } from 'vue';
import { kinetixFetch, kinetixRoutePrefix } from '@/composables/useKinetixHttp';
import type { KinetixMailTemplate, KinetixSharedProps } from '@/types/kinetix';

/**
 * Manage editable mail templates: list, create/update, delete, live-preview
 * (renders unsaved editor content) and send a test email.
 */
export function useKinetixMailTemplates() {
    const page = usePage<KinetixSharedProps>();
    const base = (): string => `/${kinetixRoutePrefix(page)}/mail-templates`;

    const templates = ref<KinetixMailTemplate[]>([]);
    const loading = ref(false);

    async function load(): Promise<void> {
        loading.value = true;

        try {
            const res = await kinetixFetch<{
                templates: KinetixMailTemplate[];
            }>(base());
            templates.value = res?.templates ?? [];
        } finally {
            loading.value = false;
        }
    }

    async function save(
        template: KinetixMailTemplate,
    ): Promise<KinetixMailTemplate | null> {
        const isUpdate = template.id != null;
        const res = await kinetixFetch<{ template: KinetixMailTemplate }>(
            isUpdate ? `${base()}/${template.id}` : base(),
            { method: isUpdate ? 'PUT' : 'POST', body: template },
        );

        await load();

        return res?.template ?? null;
    }

    async function remove(id: number | string): Promise<void> {
        await kinetixFetch(`${base()}/${id}`, { method: 'DELETE' });
        await load();
    }

    async function preview(payload: {
        subject: string;
        body: string;
        format: string;
        data: Record<string, unknown>;
    }): Promise<{ subject: string; html: string } | null> {
        return kinetixFetch(`${base()}/preview`, {
            method: 'POST',
            body: payload,
        });
    }

    async function sendTest(id: number | string, email: string): Promise<void> {
        await kinetixFetch(`${base()}/${id}/test`, {
            method: 'POST',
            body: { email },
        });
    }

    return { templates, loading, load, save, remove, preview, sendTest };
}
