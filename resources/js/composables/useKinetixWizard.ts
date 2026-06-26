import { usePage } from "@inertiajs/vue3";
import { kinetixFetch, kinetixRoutePrefix } from "@/composables/useKinetixHttp";
import type { KinetixSharedProps } from "@/types";

/**
 * Server interaction for gated wizards: marks a wizard slug complete so the
 * `kinetix.wizard:<slug>` middleware lets the user through. Used by
 * <KinetixWizard> when a `slug` is provided.
 */
export function useKinetixWizard() {
  const page = usePage<KinetixSharedProps>();
  const base = (slug: string): string =>
    `/${kinetixRoutePrefix(page)}/wizards/${slug}`;

  async function complete(slug: string): Promise<void> {
    await kinetixFetch(`${base(slug)}/complete`, { method: "POST" });
  }

  async function status(slug: string): Promise<boolean> {
    const data = await kinetixFetch<{ completed: boolean }>(base(slug));
    return data?.completed ?? false;
  }

  return { complete, status };
}
