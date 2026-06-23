import { config, mount } from "@vue/test-utils";
import { describe, expect, it } from "vitest";
import KinetixDatePicker from "../KinetixDatePicker.vue";
import KinetixDateTimePicker from "../KinetixDateTimePicker.vue";
import { i18n } from "./i18n";

config.global.plugins = [i18n];

describe("KinetixDatePicker", () => {
  it("renders a native date input in native mode and emits the value", async () => {
    const wrapper = mount(KinetixDatePicker, { props: { native: true } });
    const input = wrapper.get('input[type="date"]');

    await input.setValue("2026-03-15");

    expect(wrapper.emitted("update:value")?.[0]).toEqual(["2026-03-15"]);
  });

  it("renders the shadcn trigger (button) by default with the placeholder", () => {
    const wrapper = mount(KinetixDatePicker, {
      props: { placeholder: "Pick a date" },
    });

    expect(wrapper.find('input[type="date"]').exists()).toBe(false);
    expect(wrapper.text()).toContain("Pick a date");
  });
});

describe("KinetixDateTimePicker", () => {
  it("renders a native datetime input in native mode and emits the value", async () => {
    const wrapper = mount(KinetixDateTimePicker, { props: { native: true } });
    const input = wrapper.get('input[type="datetime-local"]');

    await input.setValue("2026-03-15T13:30");

    expect(wrapper.emitted("update:value")?.[0]).toEqual(["2026-03-15T13:30"]);
  });

  it("shows the placeholder when empty and a formatted value when set", () => {
    const empty = mount(KinetixDateTimePicker, {
      props: { placeholder: "MM/DD/YYYY hh:mm" },
    });
    expect(empty.text()).toContain("MM/DD/YYYY hh:mm");

    const filled = mount(KinetixDateTimePicker, {
      props: { value: "2026-03-15T13:30", locale: "en-US" },
    });
    // Localized datetime — just assert it isn't the placeholder and has digits.
    expect(filled.text()).not.toContain("MM/DD/YYYY");
    expect(filled.text()).toMatch(/2026/);
  });
});
