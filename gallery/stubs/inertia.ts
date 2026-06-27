import { defineComponent, h } from "vue";

/**
 * Minimal @inertiajs/vue3 stub for the screenshot gallery — just enough for the
 * Kinetix components to mount without a real Inertia runtime. No navigation
 * actually happens (screenshots are static).
 */
const page = {
  props: {
    kinetix_config: { route_prefix: "_kinetix" },
    // Surfaced for the impersonation banner specimen.
    kinetix_impersonation: { active: true, user: { id: 1, name: "Ada Lovelace" } },
    // Surfaced for the accessibility panel specimen.
    kinetix_accessibility: {
      reducedMotion: true,
      highContrast: false,
      textSize: "large",
      underlineLinks: true,
      enhancedFocus: false,
    },
  },
  url: "/",
  component: "Gallery",
  version: null as string | null,
};

export function usePage() {
  return page;
}

export const router = {
  get() {},
  post() {},
  put() {},
  patch() {},
  delete() {},
  visit() {},
  reload() {},
  cancelAll() {},
  on() {
    return () => {};
  },
};

export const Link = defineComponent({
  name: "InertiaLinkStub",
  props: { href: { type: String, default: "#" } },
  setup(_, { slots }) {
    return () => h("a", { href: "#" }, slots.default?.());
  },
});

export function usePoll() {
  return { start() {}, stop() {} };
}

export function useForm(data: Record<string, unknown> = {}) {
  return { ...data, processing: false, errors: {}, post() {}, put() {}, get() {}, delete() {} };
}

export const Head = defineComponent({
  name: "InertiaHeadStub",
  setup: () => () => null,
});
