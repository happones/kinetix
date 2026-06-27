import { mount } from "@vue/test-utils";
import { describe, expect, it, vi } from "vitest";
import { createI18n } from "vue-i18n";
import KinetixSlugInput from "@/components/KinetixSlugInput.vue";
import KinetixSignaturePad from "@/components/KinetixSignaturePad.vue";

const i18n = createI18n({
  legacy: false,
  locale: "en",
  missingWarn: false,
  missing: (_l: string, key: string) => key,
  messages: { en: { kinetix: {} } },
});

describe("KinetixSlugInput", () => {
  it("auto-slugifies the source value while untouched", async () => {
    const w = mount(KinetixSlugInput, {
      props: { value: "", source: "", config: { from: "title", separator: "-" } },
    });
    await w.setProps({ source: "Hello, World! 2026" });
    const events = w.emitted("update:value");
    expect(events![events!.length - 1][0]).toBe("hello-world-2026");
  });

  it("stops auto-syncing once the user edits the slug", async () => {
    const w = mount(KinetixSlugInput, {
      props: { value: "", source: "", config: { from: "title", separator: "-" } },
    });
    await w.find("input").setValue("Custom Slug");
    expect(w.emitted("update:value")!.at(-1)![0]).toBe("custom-slug");

    await w.setProps({ source: "Something Else" });
    // No further emit from the source change.
    expect(w.emitted("update:value")!.at(-1)![0]).toBe("custom-slug");
  });
});

describe("KinetixSignaturePad", () => {
  it("renders a canvas and a clear button, and clears to null", async () => {
    // happy-dom canvas has no real 2d context; stub getContext.
    HTMLCanvasElement.prototype.getContext = vi.fn(() => ({
      scale: vi.fn(),
      clearRect: vi.fn(),
      fillRect: vi.fn(),
      beginPath: vi.fn(),
      moveTo: vi.fn(),
      lineTo: vi.fn(),
      stroke: vi.fn(),
    })) as never;

    const w = mount(KinetixSignaturePad, {
      props: { value: null, config: { height: 160 } },
      global: { plugins: [i18n] },
    });
    expect(w.find("canvas").exists()).toBe(true);
    await w.find("button").trigger("click");
    expect(w.emitted("update:value")!.at(-1)![0]).toBe(null);
  });
});
