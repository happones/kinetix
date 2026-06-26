import { usePage } from "@inertiajs/vue3";
import { ref } from "vue";
import { kinetixFetch, kinetixRoutePrefix } from "@/composables/useKinetixHttp";
import type { KinetixActivityResponse, KinetixSharedProps } from "@/types";

/**
 * Loads the paginated, team-scoped activity feed. Pass `subject_type` +
 * `subject_id` for a per-record (per-feature) view, or nothing for the global
 * feed. The route prefix (incl. any team segment) comes from `kinetix_config`.
 */
export function useKinetixActivity() {
  const page = usePage<KinetixSharedProps>();
  const base = (): string => `/${kinetixRoutePrefix(page)}/activity`;

  const loading = ref(false);

  async function load(
    params: Record<string, string | number> = {},
  ): Promise<KinetixActivityResponse | null> {
    loading.value = true;

    const query = new URLSearchParams(
      Object.entries(params).reduce<Record<string, string>>((acc, [k, v]) => {
        if (v !== "" && v !== undefined && v !== null) {
          acc[k] = String(v);
        }
        return acc;
      }, {}),
    ).toString();

    try {
      return await kinetixFetch<KinetixActivityResponse>(
        query ? `${base()}?${query}` : base(),
      );
    } finally {
      loading.value = false;
    }
  }

  return { loading, load };
}
