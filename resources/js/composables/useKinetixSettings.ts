import { usePage } from "@inertiajs/vue3";
import { ref } from "vue";
import { kinetixFetch, kinetixRoutePrefix } from "@/composables/useKinetixHttp";
import type { KinetixSharedProps } from "@/types";

/**
 * Persists a Kinetix settings page. Talks to the `settings` endpoint; the route
 * prefix (incl. any team segment) comes from the shared `kinetix_config`.
 */
export function useKinetixSettings() {
  const page = usePage<KinetixSharedProps>();
  const base = (): string => `/${kinetixRoutePrefix(page)}/settings`;

  const saving = ref(false);

  async function save(
    pageKey: string,
    values: Record<string, unknown>,
  ): Promise<unknown> {
    saving.value = true;

    try {
      return await kinetixFetch(`${base()}/${pageKey}`, {
        method: "PUT",
        body: values,
      });
    } finally {
      saving.value = false;
    }
  }

  return { saving, save };
}
