import { usePage } from "@inertiajs/vue3";
import { ref } from "vue";
import type {
  KinetixPermissionFeature,
  KinetixRole,
  KinetixSharedProps,
} from "@/types";

/** Read Laravel's XSRF-TOKEN cookie for fetch() requests. */
function xsrfToken(): string {
  const match = document.cookie
    .split("; ")
    .find((row) => row.startsWith("XSRF-TOKEN="));

  return match ? decodeURIComponent(match.split("=")[1]) : "";
}

/**
 * CRUD for the role-management UI, talking to Kinetix's permission endpoints.
 * The route prefix (incl. any team segment) comes from the shared `kinetix_config`.
 */
export function useKinetixRoles() {
  const page = usePage<KinetixSharedProps>();
  const base = (): string =>
    `/${page.props.kinetix_config?.route_prefix ?? "_kinetix"}/permissions`;

  const features = ref<KinetixPermissionFeature[]>([]);
  const roles = ref<KinetixRole[]>([]);
  const loading = ref(false);

  async function request(
    url: string,
    method: string,
    body?: unknown,
  ): Promise<unknown> {
    const response = await fetch(url, {
      method,
      headers: {
        "Content-Type": "application/json",
        Accept: "application/json",
        "X-Requested-With": "XMLHttpRequest",
        "X-XSRF-TOKEN": xsrfToken(),
      },
      credentials: "same-origin",
      body: body ? JSON.stringify(body) : undefined,
    });

    if (!response.ok) {
      throw new Error(`HTTP error! status: ${response.status}`);
    }

    return response.status === 204 ? null : response.json();
  }

  async function load(): Promise<void> {
    loading.value = true;

    try {
      const [loadedFeatures, loadedRoles] = await Promise.all([
        request(`${base()}/features`, "GET"),
        request(`${base()}/roles`, "GET"),
      ]);

      features.value = (loadedFeatures as KinetixPermissionFeature[]) ?? [];
      roles.value = (loadedRoles as KinetixRole[]) ?? [];
    } finally {
      loading.value = false;
    }
  }

  async function save(role: KinetixRole): Promise<unknown> {
    const payload = { name: role.name, permissions: role.permissions };

    return role.id
      ? request(`${base()}/roles/${role.id}`, "PUT", payload)
      : request(`${base()}/roles`, "POST", payload);
  }

  async function remove(role: KinetixRole): Promise<unknown> {
    return request(`${base()}/roles/${role.id}`, "DELETE");
  }

  return { features, roles, loading, load, save, remove };
}
