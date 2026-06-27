import { usePage } from "@inertiajs/vue3";
import { ref } from "vue";
import { kinetixFetch, kinetixRoutePrefix } from "@/composables/useKinetixHttp";
import type {
  KinetixConnectedAccount,
  KinetixConnectedProvider,
  KinetixSharedProps,
} from "@/types";

/**
 * Self-service connected accounts: list the user's linked OAuth providers,
 * start a link (full-page OAuth redirect), unlink, and set a password for a
 * social-only user. Talks to Kinetix's `connected-accounts` endpoints.
 */
export function useKinetixConnectedAccounts() {
  const page = usePage<KinetixSharedProps>();
  const base = (): string => `/${kinetixRoutePrefix(page)}/connected-accounts`;

  const accounts = ref<KinetixConnectedAccount[]>([]);
  const providers = ref<KinetixConnectedProvider[]>([]);
  const hasPassword = ref(true);
  const loading = ref(false);

  async function load(): Promise<void> {
    loading.value = true;
    try {
      const data = await kinetixFetch<{
        accounts: KinetixConnectedAccount[];
        providers: KinetixConnectedProvider[];
        hasPassword: boolean;
      }>(base());
      accounts.value = data?.accounts ?? [];
      providers.value = data?.providers ?? [];
      hasPassword.value = data?.hasPassword ?? true;
    } finally {
      loading.value = false;
    }
  }

  /** The full-page URL that starts the OAuth round-trip to link a provider. */
  function connectUrl(providerKey: string): string {
    return `${base()}/redirect/${providerKey}`;
  }

  async function disconnect(
    account: KinetixConnectedAccount,
  ): Promise<unknown> {
    return kinetixFetch(`${base()}/${account.id}`, { method: "DELETE" });
  }

  async function setPassword(payload: {
    password: string;
    password_confirmation: string;
    current_password?: string;
  }): Promise<{ status: string; hasPassword: boolean } | null> {
    const result = await kinetixFetch<{ status: string; hasPassword: boolean }>(
      `${base()}/password`,
      { method: "POST", body: payload },
    );
    if (result?.hasPassword) {
      hasPassword.value = true;
    }
    return result;
  }

  return {
    accounts,
    providers,
    hasPassword,
    loading,
    load,
    connectUrl,
    disconnect,
    setPassword,
  };
}
