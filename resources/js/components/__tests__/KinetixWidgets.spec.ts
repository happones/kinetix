import { mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import KinetixCustomWidget from "../KinetixCustomWidget.vue";
import KinetixStatsOverviewWidget from "../KinetixStatsOverviewWidget.vue";
import KinetixTableWidget from "../KinetixTableWidget.vue";

const baseWidget = {
  id: "w1",
  columnSpan: 12,
  sort: 0,
  title: "My widget",
  description: "Some description",
};

describe("widgets use the shadcn Card primitive", () => {
  it("CustomWidget renders a Card with header + slot content", () => {
    const wrapper = mount(KinetixCustomWidget, {
      props: { widget: { ...baseWidget, type: "custom", data: {} } },
      slots: { default: "<p>hello</p>" },
    });

    expect(wrapper.find('[data-slot="card"]').exists()).toBe(true);
    expect(wrapper.find('[data-slot="card-title"]').text()).toBe("My widget");
    expect(wrapper.html()).toContain("hello");
  });

  it("TableWidget renders a Card and its rows", () => {
    const wrapper = mount(KinetixTableWidget, {
      props: {
        widget: {
          ...baseWidget,
          type: "table",
          data: { headers: ["Name"], rows: [["Ada"]] },
        },
      },
    });

    expect(wrapper.find('[data-slot="card"]').exists()).toBe(true);
    expect(wrapper.text()).toContain("Ada");
  });

  it("StatsOverviewWidget renders one Card per stat", () => {
    const wrapper = mount(KinetixStatsOverviewWidget, {
      props: {
        widget: {
          ...baseWidget,
          type: "stats",
          data: {
            stats: [
              { label: "Users", value: "10" },
              { label: "Sales", value: "20" },
            ],
          },
        },
      },
    });

    expect(wrapper.findAll('[data-slot="card"]').length).toBe(2);
    expect(wrapper.text()).toContain("Users");
    expect(wrapper.text()).toContain("Sales");
  });
});
