import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";

vi.mock("@inertiajs/vue3", () => ({ usePage: () => ({ props: {} }) }));
vi.mock("@/composables/useKinetixHttp", () => ({
  kinetixFetch: vi.fn(),
  kinetixRoutePrefix: () => "_kinetix",
}));

import KinetixPhoneInput from "@/components/KinetixPhoneInput.vue";

const i18n = createI18n({
  legacy: false,
  locale: "en",
  missingWarn: false,
  missing: (_l: string, key: string) => key,
  messages: { en: { kinetix: {} } },
});
const opts = { global: { plugins: [i18n] } };

const config = {
  defaultCountry: "MX",
  countries: [
    { code: "US", name: "United States", dial: "1" },
    { code: "MX", name: "Mexico", dial: "52" },
    { code: "GB", name: "United Kingdom", dial: "44" },
  ],
};

describe("KinetixPhoneInput", () => {
  it("shows the default country's dial code prefix", () => {
    const w = mount(KinetixPhoneInput, { props: { value: null, config }, ...opts });
    expect(w.text()).toContain("+52");
  });

  it("derives the country from an existing E.164 value", () => {
    const w = mount(KinetixPhoneInput, { props: { value: "+447911123456", config }, ...opts });
    expect(w.text()).toContain("+44");
    expect((w.find('input[type="tel"]').element as HTMLInputElement).value).toBe(
      "7911123456",
    );
  });

  it("emits the joined E.164 string on national input", async () => {
    const w = mount(KinetixPhoneInput, { props: { value: null, config }, ...opts });
    await w.find('input[type="tel"]').setValue("55 1234 5678");
    const events = w.emitted("update:value");
    // Default country MX (+52), digits stripped of spaces.
    expect(events!.at(-1)![0]).toBe("+525512345678");
  });
});
