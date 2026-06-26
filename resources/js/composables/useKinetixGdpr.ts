import { usePage } from "@inertiajs/vue3";
import { ref } from "vue";
import { kinetixFetch, kinetixRoutePrefix } from "@/composables/useKinetixHttp";
import type { KinetixSharedProps } from "@/types";

/**
 * Self-service GDPR actions: request a personal-data export (delivered as a
 * notification with a download link) and delete the current account.
 */
export function useKinetixGdpr() {
  const page = usePage<KinetixSharedProps>();
  const base = (): string => `/${kinetixRoutePrefix(page)}/gdpr`;

  const exporting = ref(false);
  const deleting = ref(false);

  async function exportData(): Promise<void> {
    exporting.value = true;
    try {
      await kinetixFetch(`${base()}/export`, { method: "POST" });
    } finally {
      exporting.value = false;
    }
  }

  async function deleteAccount(
    password?: string,
  ): Promise<{ redirect: string } | null> {
    deleting.value = true;
    try {
      return await kinetixFetch<{ redirect: string }>(`${base()}/delete`, {
        method: "POST",
        body: password !== undefined ? { password } : {},
      });
    } finally {
      deleting.value = false;
    }
  }

  return { exporting, deleting, exportData, deleteAccount };
}
