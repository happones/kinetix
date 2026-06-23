import { beforeEach, describe, expect, it, vi } from "vitest";
import { setActivePinia, createPinia } from "pinia";

const reload = vi.fn();
const pageProps: Record<string, unknown> = {
  kinetix_config: { database: true, route_prefix: "_kinetix" },
  kinetix_notifications: [],
  auth: { user: { id: 1 } },
};

vi.mock("@inertiajs/vue3", () => ({
  router: { reload: (...args: unknown[]) => reload(...args) },
  usePage: () => ({ props: pageProps }),
}));

vi.mock("vue-sonner", () => ({
  toast: {
    success: vi.fn(),
    warning: vi.fn(),
    error: vi.fn(),
    info: vi.fn(),
  },
}));

import { useNotificationsStore } from "../notifications";

describe("notifications store — sendRequest (database mode)", () => {
  beforeEach(() => {
    setActivePinia(createPinia());
    reload.mockReset();
    document.cookie = "XSRF-TOKEN=test-token";
  });

  it("sends the delete with JSON Accept + same-origin credentials so auth/CSRF failures aren't silently followed", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue({ ok: true, status: 200 } as Response);
    vi.stubGlobal("fetch", fetchMock);

    const store = useNotificationsStore();
    store.removeNotification("abc-123");

    // Let the awaited fetch resolve.
    await vi.waitFor(() => expect(fetchMock).toHaveBeenCalled());

    const [url, init] = fetchMock.mock.calls[0];
    expect(url).toBe("/_kinetix/notifications/abc-123");
    expect(init.method).toBe("DELETE");
    expect(init.credentials).toBe("same-origin");
    expect(init.headers.Accept).toBe("application/json");
    expect(init.headers["X-XSRF-TOKEN"]).toBe("test-token");

    vi.unstubAllGlobals();
  });

  it("re-syncs from the server when the request fails", async () => {
    const fetchMock = vi
      .fn()
      .mockResolvedValue({ ok: false, status: 419 } as Response);
    vi.stubGlobal("fetch", fetchMock);

    const store = useNotificationsStore();
    store.removeNotification("abc-123");

    await vi.waitFor(() => expect(reload).toHaveBeenCalled());
    expect(reload.mock.calls[0][0]).toMatchObject({
      only: ["kinetix_notifications"],
    });

    vi.unstubAllGlobals();
  });
});
