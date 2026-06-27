import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

vi.mock("@inertiajs/vue3", () => ({ usePage: () => ({ props: {} }) }));
vi.mock("@/composables/useKinetixHttp", () => ({
  kinetixRoutePrefix: () => "_kinetix",
}));

import KinetixSocialButton from "@/components/KinetixSocialButton.vue";

const i18n = createI18n({
  legacy: false,
  locale: "en",
  missingWarn: false,
  messages: { en: { kinetix: { continue_with: "Continue with {provider}" } } },
});

const mountIt = (props: Record<string, unknown>) =>
  mount(KinetixSocialButton, { props, global: { plugins: [i18n] } });

describe("KinetixSocialButton", () => {
  it("links to the login redirect and labels by brand", () => {
    const w = mountIt({ provider: "github", mode: "login" });
    expect(w.get("a").attributes("href")).toBe(
      "/_kinetix/connected-accounts/login/redirect/github",
    );
    expect(w.text()).toBe("Continue with GitHub");
    expect(w.find("svg").exists()).toBe(true);
  });

  it("uses the link redirect in link mode", () => {
    const w = mountIt({ provider: "google", mode: "link" });
    expect(w.get("a").attributes("href")).toBe(
      "/_kinetix/connected-accounts/redirect/google",
    );
  });

  it("falls back to a generic glyph and title-cased label for unknown providers", () => {
    const w = mountIt({ provider: "okta" });
    expect(w.text()).toBe("Continue with Okta");
    expect(w.find("svg").exists()).toBe(true);
  });

  it("honors an explicit href override and custom label", () => {
    const w = mountIt({ provider: "github", href: "/custom", label: "Sign in" });
    expect(w.get("a").attributes("href")).toBe("/custom");
    expect(w.text()).toBe("Sign in");
  });
});
