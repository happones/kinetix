import { flushPromises, mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

vi.mock("@inertiajs/vue3", () => ({ usePage: () => ({ props: {} }) }));

const fetchMock = vi.fn();
vi.mock("@/composables/useKinetixHttp", () => ({
  kinetixFetch: (...args: unknown[]) => fetchMock(...args),
  kinetixRoutePrefix: () => "_kinetix",
}));

vi.mock("vue-sonner", () => ({ toast: { success: vi.fn(), error: vi.fn() } }));

import KinetixSessions from "@/components/KinetixSessions.vue";

const i18n = createI18n({
  legacy: false,
  locale: "en",
  missingWarn: false,
  missing: (_l, key) => key,
  messages: { en: { kinetix: {} } },
});

const mountIt = () => mount(KinetixSessions, { global: { plugins: [i18n] } });

const session = (over: Record<string, unknown> = {}) => ({
  id: "s1",
  ipAddress: "203.0.113.10",
  browser: "Chrome",
  platform: "Windows",
  device: "desktop",
  isCurrentDevice: false,
  lastActive: "2026-06-26T10:00:00Z",
  ...over,
});

describe("KinetixSessions", () => {
  it("lists sessions and flags the current device", async () => {
    fetchMock.mockResolvedValueOnce({
      sessions: [
        session({ id: "cur", isCurrentDevice: true }),
        session({ id: "other", platform: "macOS" }),
      ],
      databaseDriver: true,
      requiresPassword: true,
    });

    const w = mountIt();
    await flushPromises();

    expect(w.findAll("li")).toHaveLength(2);
    expect(w.text()).toContain("session_this_device");
    expect(w.text()).toContain("Chrome · Windows");
    // More than one session → the "log out others" control is shown.
    expect(w.text()).toContain("sessions_logout_others");
  });

  it("shows a notice when the database driver is not used", async () => {
    fetchMock.mockResolvedValueOnce({
      sessions: [],
      databaseDriver: false,
      requiresPassword: false,
    });

    const w = mountIt();
    await flushPromises();

    expect(w.text()).toContain("sessions_unavailable");
  });

  it("requires a password before logging out others when configured", async () => {
    fetchMock.mockResolvedValueOnce({
      sessions: [
        session({ id: "cur", isCurrentDevice: true }),
        session({ id: "other" }),
      ],
      databaseDriver: true,
      requiresPassword: true,
    });

    const w = mountIt();
    await flushPromises();

    await w
      .findAll("button")
      .find((b) => b.text().includes("sessions_logout_others"))!
      .trigger("click");

    expect(w.find('input[type="password"]').exists()).toBe(true);
  });
});
