import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import KinetixRangeCalendar from "../KinetixRangeCalendar.vue";

describe("KinetixRangeCalendar", () => {
  it("mounts the shadcn/Reka range calendar with a preset range", () => {
    const wrapper = mount(KinetixRangeCalendar, {
      props: { value: { from: "2026-03-01", to: "2026-03-10" } },
    });

    // Renders the month heading and a grid of day-cell triggers.
    expect(wrapper.text()).toContain("March 2026");
    expect(wrapper.findAll("table").length).toBeGreaterThan(0);
    expect(wrapper.text()).toContain("15");
  });

  it("renders without a value (empty range)", () => {
    const wrapper = mount(KinetixRangeCalendar, { props: { value: null } });

    expect(wrapper.findAll("table").length).toBeGreaterThan(0);
  });

  it("renders multiple months when numberOfMonths is set", () => {
    const wrapper = mount(KinetixRangeCalendar, {
      props: { value: { from: "2026-03-01", to: null }, numberOfMonths: 2 },
    });

    // Two month grids; Reka shows a combined "March - April 2026" heading.
    expect(wrapper.findAll("table")).toHaveLength(2);
    expect(wrapper.text()).toContain("March");
    expect(wrapper.text()).toContain("April");
  });

  it("localizes the heading", () => {
    const wrapper = mount(KinetixRangeCalendar, {
      props: { value: { from: "2026-03-01", to: null }, locale: "es" },
    });

    expect(wrapper.text().toLowerCase()).toContain("marzo");
  });

  it("renders full weekday names with weekdayFormat=long", () => {
    const wrapper = mount(KinetixRangeCalendar, {
      props: { value: { from: "2026-03-01", to: null }, weekdayFormat: "long" },
    });

    expect(wrapper.text()).toContain("Wednesday");
  });

  it("renders 6 week rows when fixedWeeks is set", () => {
    const wrapper = mount(KinetixRangeCalendar, {
      props: { value: { from: "2026-03-01", to: null }, fixedWeeks: true },
    });

    expect(wrapper.findAll("tbody tr")).toHaveLength(6);
  });

  it("disables dates beyond maxValue", () => {
    const wrapper = mount(KinetixRangeCalendar, {
      props: { value: { from: "2026-03-05", to: null }, maxValue: "2026-03-10" },
    });

    // Days after the 10th of March are out of range and disabled.
    expect(wrapper.findAll("[data-disabled]").length).toBeGreaterThan(0);
  });
});
