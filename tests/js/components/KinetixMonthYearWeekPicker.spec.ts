import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import { createI18n } from "vue-i18n";
import KinetixMonthPicker from "@/components/KinetixMonthPicker.vue";
import KinetixYearPicker from "@/components/KinetixYearPicker.vue";
import KinetixWeekPicker from "@/components/KinetixWeekPicker.vue";

const i18n = createI18n({
  legacy: false,
  locale: "en",
  missingWarn: false,
  messages: {
    en: {
      kinetix: {
        pick_month: "Pick a month",
        pick_year: "Pick a year",
        pick_week: "Pick a week",
        week_of: "Week {week}, {year}",
      },
    },
  },
});

const mountWith = (c: any, props: Record<string, any>) =>
  mount(c, { props, global: { plugins: [i18n] } });

describe("Month/Year/Week pickers — native", () => {
  it("MonthPicker native renders <input type=month> with min/max", () => {
    const w = mountWith(KinetixMonthPicker, {
      native: true,
      value: "2026-06",
      minValue: "2026-01",
      maxValue: "2026-12",
    });
    const input = w.find('input[type="month"]');
    expect(input.exists()).toBe(true);
    expect(input.attributes("min")).toBe("2026-01");
    expect(input.attributes("max")).toBe("2026-12");
    expect((input.element as HTMLInputElement).value).toBe("2026-06");
  });

  it("YearPicker native renders a number input with min/max", () => {
    const w = mountWith(KinetixYearPicker, {
      native: true,
      value: "2026",
      minValue: "2020",
      maxValue: "2030",
    });
    const input = w.find('input[type="number"]');
    expect(input.attributes("min")).toBe("2020");
    expect(input.attributes("max")).toBe("2030");
  });

  it("WeekPicker native renders <input type=week>", () => {
    const w = mountWith(KinetixWeekPicker, { native: true, value: "2026-W25" });
    const input = w.find('input[type="week"]');
    expect(input.exists()).toBe(true);
    expect((input.element as HTMLInputElement).value).toBe("2026-W25");
  });
});

describe("shadcn picker triggers", () => {
  // The grid/calendar panels render in a teleported popover (not queryable in
  // happy-dom), so assert the trigger label reflects the value.
  it("MonthPicker trigger shows the formatted month", () => {
    const w = mountWith(KinetixMonthPicker, { value: "2026-06" });
    expect(w.find("button").text()).toContain("2026");
  });

  it("WeekPicker trigger shows the localized week label", () => {
    const w = mountWith(KinetixWeekPicker, { value: "2026-W25" });
    expect(w.find("button").text()).toContain("Week 25, 2026");
  });

  it("YearPicker trigger shows the selected year", () => {
    const w = mountWith(KinetixYearPicker, { value: "2026" });
    expect(w.find("button").text()).toContain("2026");
  });
});
