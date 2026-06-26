import { usePage } from "@inertiajs/vue3";
import { ref } from "vue";
import { kinetixFetch, kinetixRoutePrefix } from "@/composables/useKinetixHttp";
import type { KinetixSharedProps, KinetixToken } from "@/types";

/**
 * Self-service personal access tokens, talking to Kinetix's `tokens`
 * endpoints. The plaintext token is returned only by `create()` and must be
 * surfaced to the user exactly once.
 */
export function useKinetixTokens() {
  const page = usePage<KinetixSharedProps>();
  const base = (): string => `/${kinetixRoutePrefix(page)}/tokens`;

  const tokens = ref<KinetixToken[]>([]);
  const scopes = ref<Record<string, string>>({});
  const loading = ref(false);

  async function load(): Promise<void> {
    loading.value = true;
    try {
      const data = await kinetixFetch<{
        tokens: KinetixToken[];
        scopes: Record<string, string>;
      }>(base());
      tokens.value = data?.tokens ?? [];
      scopes.value = data?.scopes ?? {};
    } finally {
      loading.value = false;
    }
  }

  async function create(payload: {
    name: string;
    abilities: string[];
  }): Promise<{ token: KinetixToken; plainTextToken: string } | null> {
    return kinetixFetch<{ token: KinetixToken; plainTextToken: string }>(
      base(),
      { method: "POST", body: payload },
    );
  }

  async function remove(token: KinetixToken): Promise<unknown> {
    return kinetixFetch(`${base()}/${token.id}`, { method: "DELETE" });
  }

  return { tokens, scopes, loading, load, create, remove };
}
