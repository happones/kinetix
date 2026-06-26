import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { createI18n } from "vue-i18n";
import KinetixDateRangePicker from "@/components/KinetixDateRangePicker.vue";

const i18n = createI18n({
  legacy: false,
  locale: "en",
  missingWarn: false,
  messages: { en: { kinetix: { pick_date_range: "Pick a date range" } } },
});

const mountWith = (props: Record<string, any>) =>
  mount(KinetixDateRangePicker, { props, global: { plugins: [i18n] } });

describe("KinetixDateRangePicker", () => {
  it("native renders two date inputs and emits {from,to}", async () => {
    const w = mountWith({ native: true, value: { from: "2026-06-01", to: null } });
    const inputs = w.findAll('input[type="date"]');
    expect(inputs).toHaveLength(2);

    await inputs[1].setValue("2026-06-30");
    await inputs[1].trigger("change");

    expect(w.emitted("update:value")?.[0]).toEqual([
      { from: "2026-06-01", to: "2026-06-30" },
    ]);
  });

  it("native passes min/max bounds to both inputs", () => {
    const w = mountWith({ native: true, minValue: "2026-01-01", maxValue: "2026-12-31" });
    const inputs = w.findAll('input[type="date"]');
    expect(inputs[0].attributes("min")).toBe("2026-01-01");
    expect(inputs[1].attributes("max")).toBe("2026-12-31");
  });

  it("shadcn trigger shows the formatted range", () => {
    const w = mountWith({ value: { from: "2026-06-01", to: "2026-06-30" } });
    const text = w.find("button").text();
    expect(text).toContain("–");
    expect(text).toContain("2026");
  });
});
