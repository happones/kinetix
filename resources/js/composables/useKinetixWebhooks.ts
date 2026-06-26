import { usePage } from "@inertiajs/vue3";
import { ref } from "vue";
import { kinetixFetch, kinetixRoutePrefix } from "@/composables/useKinetixHttp";
import type {
  KinetixSharedProps,
  KinetixWebhookEndpoint,
  KinetixWebhookLog,
} from "@/types";

/**
 * CRUD + operations for the customer webhook dashboard, talking to Kinetix's
 * `webhooks` endpoints. Secrets are returned only by `create()` / `rotate()`.
 */
export function useKinetixWebhooks() {
  const page = usePage<KinetixSharedProps>();
  const base = (): string => `/${kinetixRoutePrefix(page)}/webhooks`;

  const endpoints = ref<KinetixWebhookEndpoint[]>([]);
  const availableEvents = ref<Record<string, string>>({});
  const loading = ref(false);

  async function load(): Promise<void> {
    loading.value = true;
    try {
      const data = await kinetixFetch<{
        endpoints: KinetixWebhookEndpoint[];
        events: Record<string, string>;
      }>(base());
      endpoints.value = data?.endpoints ?? [];
      availableEvents.value = data?.events ?? {};
    } finally {
      loading.value = false;
    }
  }

  async function create(
    payload: Partial<KinetixWebhookEndpoint>,
  ): Promise<{ secret: string } | null> {
    return kinetixFetch<{ secret: string }>(base(), {
      method: "POST",
      body: payload,
    });
  }

  async function update(
    endpoint: KinetixWebhookEndpoint,
    payload: Partial<KinetixWebhookEndpoint>,
  ): Promise<unknown> {
    return kinetixFetch(`${base()}/${endpoint.id}`, {
      method: "PUT",
      body: payload,
    });
  }

  async function remove(endpoint: KinetixWebhookEndpoint): Promise<unknown> {
    return kinetixFetch(`${base()}/${endpoint.id}`, { method: "DELETE" });
  }

  async function rotate(
    endpoint: KinetixWebhookEndpoint,
  ): Promise<{ secret: string } | null> {
    return kinetixFetch<{ secret: string }>(`${base()}/${endpoint.id}/rotate`, {
      method: "POST",
    });
  }

  async function test(endpoint: KinetixWebhookEndpoint): Promise<unknown> {
    return kinetixFetch(`${base()}/${endpoint.id}/test`, { method: "POST" });
  }

  async function logs(
    endpoint: KinetixWebhookEndpoint,
  ): Promise<KinetixWebhookLog[]> {
    const data = await kinetixFetch<{ data: KinetixWebhookLog[] }>(
      `${base()}/${endpoint.id}/logs`,
    );
    return data?.data ?? [];
  }

  return {
    endpoints,
    availableEvents,
    loading,
    load,
    create,
    update,
    remove,
    rotate,
    test,
    logs,
  };
}
